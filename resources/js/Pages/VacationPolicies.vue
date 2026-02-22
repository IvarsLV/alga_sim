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
    if (status === 'apmaksāts') return 'Apmaksāts';
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

                            <div class="p-5 bg-gray-50 border border-gray-100 rounded-xl space-y-5">
                                <!-- Row 1: Accrual toggle + Norm/Unit -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
                                    <div class="pt-2">
                                        <label class="flex items-start cursor-pointer group">
                                            <div class="flex items-center h-5">
                                                <input type="checkbox" class="w-5 h-5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 transition" v-model="form.is_accruable" />
                                            </div>
                                            <div class="ml-3 text-sm">
                                                <span class="font-semibold text-gray-900 block group-hover:text-indigo-600 transition">Uzkrāj bilanci</span>
                                                <span class="text-gray-500 font-normal mt-0.5 block">Vai šim atvaļinājumam tiek veidots dienu uzkrājums.</span>
                                            </div>
                                        </label>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel for="norm_days" value="Norma d./gadā" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                            <input id="norm_days" type="number" step="0.5" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 disabled:bg-gray-200 disabled:text-gray-400 transition" v-model="form.norm_days" :disabled="!form.is_accruable" />
                                        </div>
                                        <div>
                                            <InputLabel value="Mērvienība" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                            <select v-model="form.rules.measure_unit" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 transition">
                                                <option value="DD">DD (Darba dienas)</option>
                                                <option value="KD">KD (Kalendārās dienas)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 2: Core method settings -->
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-gray-200">
                                    <div>
                                        <InputLabel value="Uzkrāšanas metode" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <select v-model="form.rules.accrual_method" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 transition text-sm">
                                            <option value="monthly">Ikmēneša</option>
                                            <option value="yearly">Ikgadēja</option>
                                            <option value="per_event">Pēc notikuma</option>
                                            <option value="on_request">Pēc pieprasījuma</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Perioda tips" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <select v-model="form.rules.period_type" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 transition text-sm">
                                            <option value="working_year">Darba gads</option>
                                            <option value="calendar_year">Kalendārais gads</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Apmaksa" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <select v-model="form.rules.payment_status" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 transition text-sm">
                                            <option value="apmaksāts">Apmaksāts</option>
                                            <option value="neapmaksāts">Neapmaksāts</option>
                                            <option value="VSAA">VSAA</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Finanšu formula" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <select v-model="form.rules.financial_formula" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 transition text-sm">
                                            <option value="average_salary">Vidējā izpeļņa</option>
                                            <option value="base_salary">Pamatalga</option>
                                            <option value="unpaid">Neapmaksāts</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Row 3: Law reference + Shifts working year -->
                                <div class="grid grid-cols-2 gap-4 pt-4 border-t border-gray-200">
                                    <div>
                                        <InputLabel value="Likuma atsauce" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <input type="text" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 text-sm" v-model="form.rules.law_reference" placeholder="piem. DL 149" />
                                    </div>
                                    <div class="pt-7">
                                        <label class="flex items-center cursor-pointer group">
                                            <input type="checkbox" class="w-5 h-5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 transition" v-model="form.rules.shifts_working_year" />
                                            <span class="ml-3 text-sm font-semibold text-gray-900 group-hover:text-indigo-600 transition">Pārceļ darba gadu (>28 d.)</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- EXPIRATION / ANULĒŠANA -->
                            <div class="p-5 bg-amber-50 border border-amber-100 rounded-xl space-y-4">
                                <h3 class="text-sm font-bold text-amber-800 uppercase tracking-wider">⏰ Anulēšanas noteikumi</h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div>
                                        <InputLabel value="Pārnešana (gadi)" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <input type="number" step="1" min="0" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-amber-500 focus:ring-amber-500 text-gray-900 text-sm" v-model.number="form.rules.carry_over_years" placeholder="—" />
                                        <p class="text-[10px] text-gray-400 mt-1">Cik gadus var pārnest</p>
                                    </div>
                                    <div>
                                        <InputLabel value="Termiņš (mēneši)" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <input type="number" step="1" min="0" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-amber-500 focus:ring-amber-500 text-gray-900 text-sm" v-model.number="form.rules.usage_deadline_months" placeholder="—" />
                                        <p class="text-[10px] text-gray-400 mt-1">Pēc notikuma, mēnešos</p>
                                    </div>
                                    <div>
                                        <InputLabel value="Termiņš (dienas)" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <input type="number" step="1" min="0" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-amber-500 focus:ring-amber-500 text-gray-900 text-sm" v-model.number="form.rules.usage_deadline_days" placeholder="—" />
                                        <p class="text-[10px] text-gray-400 mt-1">Pēc notikuma, dienās</p>
                                    </div>
                                    <div class="pt-7">
                                        <label class="flex items-center cursor-pointer group">
                                            <input type="checkbox" class="w-4 h-4 rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500 transition" v-model="form.rules.expires_end_of_period" />
                                            <span class="ml-2 text-sm font-medium text-gray-700">Anulējas perioda beigās</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- PER-EVENT SETTINGS (only shown when per_event) -->
                            <div v-if="form.rules.accrual_method === 'per_event'" class="p-5 bg-blue-50 border border-blue-100 rounded-xl space-y-4">
                                <h3 class="text-sm font-bold text-blue-800 uppercase tracking-wider">📄 Notikuma iestatījumi</h3>
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                    <div>
                                        <InputLabel value="Dokumenta tips" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <select v-model="form.rules.event_source" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500 text-gray-900 text-sm">
                                            <option :value="null">— Nav —</option>
                                            <option value="child_registration">Bērna reģistrācija</option>
                                            <option value="donor_day">Donora diena</option>
                                            <option value="maternity">Grūtniecības dokuments</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Dienas par notikumu" class="mb-1.5 text-gray-700 font-medium text-sm" />
                                        <input type="number" step="1" min="1" class="block w-full border-gray-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500 text-gray-900 text-sm" v-model.number="form.rules.event_days" placeholder="1" />
                                    </div>
                                    <div class="pt-7">
                                        <label class="flex items-center cursor-pointer group">
                                            <input type="checkbox" class="w-4 h-4 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 transition" v-model="form.rules.requires_hire_date_check" />
                                            <span class="ml-2 text-sm font-medium text-gray-700">Pārbaudīt pieņ. datumu</span>
                                        </label>
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
