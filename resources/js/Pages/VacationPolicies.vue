<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    configs: Array,
});

const isModalOpen = ref(false);
const editMode = ref(false);

const form = useForm({
    id: null,
    tip: '',
    name: '',
    description: '',
    is_accruable: false,
    norm_days: 0,
    rules: {
        measure_unit: 'DD',
        financial_formula: 'unpaid',
        shifts_working_year: false,
    },
});

const openCreate = () => {
    editMode.value = false;
    form.reset();
    isModalOpen.value = true;
};

const openEdit = (config) => {
    editMode.value = true;
    form.id = config.id;
    form.tip = config.tip;
    form.name = config.name;
    form.description = config.description || '';
    form.is_accruable = Boolean(config.is_accruable);
    form.norm_days = config.norm_days;
    
    let parsedRules = typeof config.rules === 'string' ? JSON.parse(config.rules) : (config.rules || {});
    form.rules = {
        measure_unit: 'DD',
        financial_formula: 'unpaid',
        shifts_working_year: false,
        accrual_method: 'on_request',
        period_type: 'working_year',
        payment_status: 'neapmaksāts',
        law_reference: '',
        ...parsedRules
    };
    isModalOpen.value = true;
};

const deletePolicy = (id) => {
    if (confirm('Vai tiešām vēlaties dzēst šo atvaļinājuma veidu?')) {
        router.delete(route('policies.destroy', id), {
            preserveScroll: true,
        });
    }
};

const submitForm = () => {
    if (editMode.value) {
        form.put(route('policies.update', form.id), {
            onSuccess: () => { isModalOpen.value = false; form.reset(); }
        });
    } else {
        form.post(route('policies.store'), {
            onSuccess: () => { isModalOpen.value = false; form.reset(); }
        });
    }
};

const updateIsAccruable = () => {
    if (form.rules.accrual_method === 'on_request') {
        form.is_accruable = false;
        form.norm_days = 0;
    } else {
        form.is_accruable = true;
    }
};

const getExpirationMode = () => {
    if (form.rules.expires_end_of_period) return 'end_of_period';
    if (form.rules.carry_over_years !== null && form.rules.carry_over_years !== undefined) return 'carry';
    if ((form.rules.usage_deadline_months !== null && form.rules.usage_deadline_months !== undefined) || 
        (form.rules.usage_deadline_days !== null && form.rules.usage_deadline_days !== undefined)) return 'deadline';
    return 'none';
};

const setExpirationMode = (mode) => {
    // Reset all
    form.rules.expires_end_of_period = false;
    form.rules.carry_over_years = null;
    form.rules.usage_deadline_months = null;
    form.rules.usage_deadline_days = null;

    if (mode === 'end_of_period') {
        form.rules.expires_end_of_period = true;
    } else if (mode === 'carry') {
        form.rules.carry_over_years = 1; // Default
    } else if (mode === 'deadline') {
        form.rules.usage_deadline_months = 6; // Default
        form.rules.usage_deadline_days = 0;
    }
};

const rulesJsonPreview = computed(() => {
    return JSON.stringify(form.rules, null, 2);
});

const getFormulaBadgeClass = (formula) => {
    if (formula === 'average_salary') return 'bg-blue-100 text-blue-700 font-medium';
    if (formula === 'base_salary') return 'bg-purple-100 text-purple-700 font-medium';
    if (formula === 'unpaid') return 'bg-gray-100 text-gray-500 font-medium';
    return 'bg-gray-100 text-gray-600 font-medium';
};

const getFormulaLabel = (formula) => {
    if (formula === 'average_salary') return 'Vidējā izpeļņa';
    if (formula === 'base_salary') return 'Pamatalga';
    if (formula === 'unpaid') return 'Neapmaksāts';
    return formula || '—';
};

const getRules = (config) => {
    return typeof config.rules === 'string' ? JSON.parse(config.rules) : (config.rules ?? {});
};

const getPeriodLabel = (periodType) => {
    if (periodType === 'working_year') return 'darba gads';
    if (periodType === 'calendar_year') return 'kal. gads';
    return periodType || '—';
};

const getPaymentLabel = (status) => {
    if (status === 'apmaksāts') return 'Uzņēmums';
    if (status === 'neapmaksāts') return 'Neapmaksāts';
    if (status === 'VSAA') return 'VSAA';
    return '—';
};

const getLawColor = (lawRef) => {
    if (!lawRef) return '#6b7280';
    if (lawRef.includes('149')) return '#2563eb';
    if (lawRef.includes('150') || lawRef.includes('151')) return '#7c3aed';
    if (lawRef.includes('153')) return '#dc2626';
    if (lawRef.includes('154')) return '#db2777';
    if (lawRef.includes('155')) return '#0891b2';
    if (lawRef.includes('156')) return '#ea580c';
    if (lawRef.includes('157')) return '#059669';
    if (lawRef.includes('74')) return '#d97706';
    return '#6b7280';
};
</script>

<template>
    <Head title="Atvaļinājumu veidu konfigurācija" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">
                        Atvaļinājumu veidu konfigurācija
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">Pārvaldiet atvaļinājumu tipus, formulas un juridiskos noteikumus.</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2 bg-[#00b050] border border-transparent rounded-md font-semibold text-sm text-white hover:bg-green-600 focus:outline-none transition">
                    + Jauns veids
                </button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                
                <div class="bg-white rounded-lg shadow overflow-hidden border border-gray-100">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 table-fixed">
                            <thead class="bg-white">
                                <tr>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider w-8 text-center uppercase">#</th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider uppercase">Nosaukums</th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-20">Likums</th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-20">Apmaksa</th>
                                    <th scope="col" class="px-2 py-2 text-center text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-12">Uzkr.</th>
                                    <th scope="col" class="px-2 py-2 text-center text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-14">Norma</th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-24">Formula</th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-28">Anulēšana</th>
                                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 tracking-wider uppercase w-20">Darbības</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <tr v-for="config in configs" :key="config.id" class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-2 py-3 whitespace-nowrap text-xs text-gray-400 text-center font-medium">
                                        {{ config.id }}
                                    </td>
                                    <td class="px-2 py-3">
                                        <div class="text-sm font-semibold text-gray-800 truncate" :title="config.name">{{ config.name }}</div>
                                        <div class="text-[11px] text-gray-400 mt-0.5 truncate max-w-[280px]" :title="config.description">
                                            {{ config.description || 'Nav apraksta' }}
                                        </div>
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold text-white" :style="{ backgroundColor: getLawColor(getRules(config)?.law_reference) }">
                                            {{ getRules(config)?.law_reference || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap">
                                        <span class="text-xs text-gray-700 font-medium" :class="getRules(config)?.payment_status === 'neapmaksāts' ? 'text-gray-400' : ''">
                                            {{ getPaymentLabel(getRules(config)?.payment_status) }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap text-center text-xs">
                                        <span v-if="config.is_accruable" class="text-emerald-500 font-medium">Jā</span>
                                        <span v-else class="text-gray-400 font-medium">Nē</span>
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap text-center text-xs text-gray-600 font-medium">
                                        {{ config.is_accruable ? config.norm_days : '—' }}
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-bold" :class="getFormulaBadgeClass(getRules(config)?.financial_formula)">
                                            {{ getFormulaLabel(getRules(config)?.financial_formula) }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-3 text-[11px] text-gray-600">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="text-gray-400">{{ getPeriodLabel(getRules(config)?.period_type) }}</span>
                                            <template v-if="getRules(config)?.carry_over_years">
                                                <span class="text-blue-600 font-medium">{{ getRules(config)?.carry_over_years }}g. pārnešana</span>
                                            </template>
                                            <template v-else-if="getRules(config)?.expires_end_of_period">
                                                <span class="text-amber-600 font-medium">Per. beigās</span>
                                            </template>
                                            <template v-else-if="getRules(config)?.usage_deadline_months">
                                                <span class="text-red-600 font-medium">{{ getRules(config)?.usage_deadline_months }} mēn.</span>
                                            </template>
                                            <template v-else-if="getRules(config)?.usage_deadline_days">
                                                <span class="text-red-600 font-medium">{{ getRules(config)?.usage_deadline_days }} d.</span>
                                            </template>
                                            <template v-else>
                                                <span class="text-gray-400">—</span>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-2 py-3 whitespace-nowrap text-right text-xs space-x-1">
                                        <button @click="openEdit(config)" class="inline-flex items-center px-1.5 py-0.5 border border-blue-200 text-blue-600 rounded text-[10px] font-medium hover:bg-blue-50 transition">✏️</button>
                                        <button @click="deletePolicy(config.id)" class="inline-flex items-center px-1.5 py-0.5 border border-red-200 text-red-500 rounded text-[10px] font-medium hover:bg-red-50 transition">🗑️</button>
                                    </td>
                                </tr>
                                <tr v-if="configs.length === 0">
                                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">Nav atrasta neviena politika.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Edit / Create Modal -->
                <Modal :show="isModalOpen" @close="isModalOpen = false" maxWidth="2xl">
                    <div class="px-8 py-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-black text-gray-900 tracking-tight">
                                {{ editMode ? 'Rediģēt atvaļinājuma veidu' : 'Izveidot atvaļinājuma veidu' }}
                            </h2>
                            <button @click="isModalOpen = false" class="text-gray-400 hover:text-gray-900 transition flex-shrink-0 bg-gray-100 hover:bg-gray-200 rounded-full w-8 h-8 flex items-center justify-center">
                                <span class="sr-only">Aizvērt</span>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        
                        <form @submit.prevent="submitForm" class="space-y-6">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="tip" value="Kods (tip) *" class="mb-1 text-gray-600 font-normal" />
                                    <input id="tip" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 bg-gray-50 text-gray-700" v-model="form.tip" required placeholder="piem. ikgadejais vai 1" />
                                </div>
                                <div>
                                    <InputLabel for="name" value="Nosaukums *" class="mb-1 text-gray-600 font-normal" />
                                    <input id="name" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 text-gray-800" v-model="form.name" required placeholder="Ikgadējais apmaksātais atvaļinājums" />
                                </div>
                            </div>
                            
                            <div>
                                <InputLabel for="description" value="Juridiskais apraksts" class="mb-1 text-gray-600 font-normal" />
                                <textarea id="description" v-model="form.description" rows="3" class="block w-full border border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-gray-800 resize-y p-3 rounded-md" placeholder="DL 149.pants. Darba ņēmējam ir tiesības uz ikgadējo apmaksāto atvaļinājumu..."></textarea>
                            </div>

                            <div class="space-y-6 pt-4">

                                <!-- Sentence 1: Accrual & Base -->
                                <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex flex-wrap items-baseline gap-2 text-gray-800 text-[15px] leading-8">
                                        <span>Piešķirt</span>
                                        <template v-if="form.rules.accrual_method !== 'on_request'">
                                            <input 
                                                type="number" 
                                                step="0.5" 
                                                v-model="form.norm_days" 
                                                class="w-20 inline-block text-center border-b-2 border-t-0 border-x-0 border-indigo-400 bg-indigo-50/50 hover:bg-indigo-50 focus:ring-0 focus:border-indigo-600 font-bold text-indigo-900 px-1 py-0 h-8 rounded-t-md transition-colors" 
                                            />
                                        </template>
                                        <template v-else>
                                            <span class="font-bold text-gray-400 italic">bez normas (pēc pieprasījuma)</span>
                                        </template>
                                        
                                        <select 
                                            v-model="form.rules.measure_unit" 
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 px-8 font-bold text-indigo-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="DD">darba dienas (DD)</option>
                                            <option value="KD">kalendārās dienas (KD)</option>
                                        </select>
                                        
                                        <span>gadā, un uzkrājumu veidot</span>
                                        
                                        <select 
                                            v-model="form.rules.accrual_method"
                                            @change="updateIsAccruable"
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 pl-1 pr-6 font-bold text-indigo-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="monthly">ik mēnesi pakāpeniski</option>
                                            <option value="yearly">uzreiz par gadu</option>
                                            <option value="per_event">tikai pēc notikuma</option>
                                            <option value="on_request">pēc pieprasījuma (bez normas)</option>
                                        </select>
                                        <span>.</span>
                                    </div>
                                    
                                    <!-- PER EVENT Extra Sentence -->
                                    <div v-if="form.rules.accrual_method === 'per_event'" class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-baseline gap-2 text-gray-600 text-[14px]">
                                        <span class="text-blue-500">ℹ️ Notikuma avots ir</span>
                                        <select v-model="form.rules.event_source" class="inline-block border-b border-t-0 border-x-0 border-blue-300 bg-transparent py-0 px-2 font-semibold text-blue-700 focus:ring-0 text-sm h-7">
                                            <option :value="null">— Nav norādīts —</option>
                                            <option value="child_registration">bērna piedzimšana (apliecība)</option>
                                            <option value="donor_day">asins nodošana d. (izziņa)</option>
                                            <option value="maternity">ārsta slapa (B lapa)</option>
                                        </select>
                                        <span>, par ko dodas maksimāli</span>
                                        <input type="number" step="1" v-model.number="form.rules.event_days" class="w-16 inline-block text-center border-b border-t-0 border-x-0 border-blue-300 py-0 px-1 font-bold h-7 text-sm mx-1 focus:ring-0 focus:border-blue-500" />
                                        <span>dienas.</span>
                                        <label class="ml-4 flex items-center cursor-pointer text-sm font-medium text-gray-500 hover:text-gray-900">
                                            <input type="checkbox" v-model="form.rules.requires_hire_date_check" class="w-4 h-4 mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                            Pārbaudīt pieņemšanas datumu
                                        </label>
                                    </div>
                                </div>

                                <!-- Sentence 2: Expiration -->
                                <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex flex-wrap items-baseline gap-2 text-gray-800 text-[15px] leading-8">
                                        <span>Neizmantotās dienas</span>
                                        <select 
                                            :value="getExpirationMode()"
                                            @change="setExpirationMode($event.target.value)"
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 pl-1 pr-6 font-bold text-amber-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="none">nepāriet (turpina krāties mūžīgi)</option>
                                            <option value="carry">tiek pārnestas uz nākamo gadu</option>
                                            <option value="end_of_period">dzēšas (anulējas) perioda beigās</option>
                                            <option value="deadline">dzēšas pēc noteikta laika</option>
                                        </select>
                                        
                                        <!-- Inline options based on choice -->
                                        <template v-if="getExpirationMode() === 'carry'">
                                            <span>uz maksimāli</span>
                                            <input type="number" step="1" min="0" v-model.number="form.rules.carry_over_years" class="w-16 inline-block text-center border-b-2 border-t-0 border-x-0 border-amber-400 bg-amber-50/50 focus:ring-0 focus:border-amber-600 font-bold text-amber-900 px-1 py-0 h-8 rounded-t-md mx-1" placeholder="gadi" />
                                            <span>gadiem.</span>
                                        </template>
                                        
                                        <template v-else-if="getExpirationMode() === 'deadline'">
                                            <span>— tās jāizmanto</span>
                                            <input type="number" step="1" min="0" v-model.number="form.rules.usage_deadline_months" class="w-16 inline-block text-center border-b-2 border-t-0 border-x-0 border-amber-400 bg-amber-50/50 focus:ring-0 focus:border-amber-600 font-bold text-amber-900 px-1 py-0 h-8 rounded-t-md mx-1" placeholder="mēn." />
                                            <span>mēnešu un</span>
                                            <input type="number" step="1" min="0" v-model.number="form.rules.usage_deadline_days" class="w-16 inline-block text-center border-b-2 border-t-0 border-x-0 border-amber-400 bg-amber-50/50 focus:ring-0 focus:border-amber-600 font-bold text-amber-900 px-1 py-0 h-8 rounded-t-md mx-1" placeholder="dien." />
                                            <span>dienu laikā pēc piešķiršanas.</span>
                                        </template>
                                        
                                        <template v-else-if="getExpirationMode() === 'end_of_period'">
                                            <span>.</span>
                                        </template>
                                        <template v-else>
                                            <span>.</span>
                                        </template>
                                    </div>
                                </div>

                                <!-- Sentence 3: Period & Mechanics -->
                                <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex flex-wrap items-baseline gap-2 text-gray-800 text-[15px] leading-8">
                                        <span>Atvaļinājuma norēķinu periods ir</span>
                                        <select 
                                            v-model="form.rules.period_type"
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 pl-1 pr-6 font-bold text-teal-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="working_year">darba gads (sākas pieņemšanas datumā)</option>
                                            <option value="calendar_year">kalendārais gads (no 1. janvāra)</option>
                                        </select>
                                        <span>.</span>
                                    </div>
                                    <div class="mt-3 pt-3 flex items-center gap-3">
                                        <label class="flex items-center cursor-pointer group">
                                            <input type="checkbox" v-model="form.rules.shifts_working_year" class="w-5 h-5 rounded border-gray-300 text-teal-600 shadow-sm focus:ring-teal-500 transition cursor-pointer" />
                                            <span class="ml-3 text-[14px] font-medium text-gray-700 group-hover:text-teal-700 transition">Ja darbinieks ilgstoši ir prom (bez algas > 4 ned.), tad pārcelt viņa darba gadu uz priekšu.</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Sentence 4: Payment & Legend -->
                                <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex flex-wrap items-baseline gap-2 text-gray-800 text-[15px] leading-8">
                                        <span>Šo atvaļinājumu apmaksā</span>
                                        <select 
                                            v-model="form.rules.payment_status"
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 pl-1 pr-6 font-bold text-emerald-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="apmaksāts">Uzņēmums</option>
                                            <option value="neapmaksāts">neviens (neapmaksāts)</option>
                                            <option value="VSAA">Valsts (VSAA)</option>
                                        </select>
                                        
                                        <span v-if="form.rules.payment_status === 'apmaksāts'">, izmantojot formulu</span>
                                        
                                        <select 
                                            v-if="form.rules.payment_status === 'apmaksāts'"
                                            v-model="form.rules.financial_formula"
                                            class="inline-block border-none bg-transparent hover:bg-gray-100 rounded-md py-0 pl-1 pr-6 font-bold text-emerald-700 focus:ring-0 cursor-pointer transition-colors"
                                        >
                                            <option value="average_salary">Vidējā izpeļņa</option>
                                            <option value="base_salary">Pamatalga / Cietā likme</option>
                                        </select>
                                        <span v-if="form.rules.payment_status === 'apmaksāts'">.</span>
                                    </div>
                                    
                                    <div class="mt-4 pt-4 border-t border-gray-100 flex items-center gap-3 text-[14px] text-gray-600">
                                        <span>Likuma atsauce grāmatvedībai un atskaitēm:</span>
                                        <input type="text" v-model="form.rules.law_reference" class="w-48 inline-block border-b border-t-0 border-x-0 border-gray-300 py-0 px-2 h-7 focus:ring-0 focus:border-gray-900 bg-transparent text-sm font-semibold" placeholder="piem. DL 149. pants" />
                                    </div>
                                </div>
                                
                            </div>

                            <div class="mt-8 bg-gray-900 rounded-xl overflow-hidden shadow-inner">
                                <div class="px-4 py-2 bg-gray-800 border-b border-gray-700 flex justify-between items-center">
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">JSON Priekšskatījums (rules)</span>
                                    <div class="flex space-x-1.5">
                                        <div class="w-2.5 h-2.5 rounded-full bg-red-400 opacity-80"></div>
                                        <div class="w-2.5 h-2.5 rounded-full bg-yellow-400 opacity-80"></div>
                                        <div class="w-2.5 h-2.5 rounded-full bg-green-400 opacity-80"></div>
                                    </div>
                                </div>
                                <pre class="p-4 text-xs font-mono text-green-400 overflow-x-auto leading-relaxed">{{ rulesJsonPreview }}</pre>
                            </div>
                            
                            <div class="mt-8 flex justify-end space-x-4 pt-6">
                                <button type="button" @click="isModalOpen = false" class="px-5 py-2.5 border border-gray-300 rounded-lg shadow-sm text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                                    Atcelt
                                </button>
                                <button type="submit" class="inline-flex justify-center px-6 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition" :disabled="form.processing">
                                    Saglabāt politiku
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
