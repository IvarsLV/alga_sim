<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    employee: Object,
    documents: Array,
    vacationConfigs: Array,
    stats: Object,
    hasHireDocument: {
        type: Boolean,
        default: false
    }
});

const isFormOpen = ref(false);
const editMode = ref(false);

const form = useForm({
    id: null,
    employee_id: props.employee.id,
    type: 'vacation',
    date_from: '',
    date_to: '',
    days: '',
    amount: '',
    vacation_config_id: '',
    payload: {},
});

const openCreate = () => {
    editMode.value = false;
    form.reset();
    form.employee_id = props.employee.id;
    form.type = 'vacation';
    isFormOpen.value = true;
};

const openEdit = (doc) => {
    editMode.value = true;
    form.id = doc.id;
    form.employee_id = doc.employee_id;
    form.type = doc.type;
    
    // For child registration, ensure date_from is populated so the DB constraint passes.
    // The backend handles saving the actual DOB in the payload.
    form.date_from = doc.date_from || new Date().toISOString().split('T')[0];
    form.date_to = doc.date_to;
    form.days = doc.days;
    form.amount = doc.amount;
    
    let payload = typeof doc.payload === 'string' ? JSON.parse(doc.payload) : doc.payload;
    form.payload = payload || {};
    form.vacation_config_id = form.payload.vacation_config_id || '';

    isFormOpen.value = true;
};

const submitForm = () => {
    if (editMode.value) {
        form.put(route('documents.update', form.id), {
            preserveScroll: true,
            onSuccess: () => {
                isFormOpen.value = false;
                form.reset();
            }
        });
    } else {
        form.post(route('documents.store'), {
            preserveScroll: true,
            onSuccess: () => {
                isFormOpen.value = false;
                form.reset();
            },
        });
    }
};

const confirmingDocumentDeletion = ref(false);
const documentToDelete = ref(null);

const confirmDeletion = (id) => {
    documentToDelete.value = id;
    confirmingDocumentDeletion.value = true;
};

const deleteDoc = () => {
    if (documentToDelete.value) {
        router.delete(route('documents.destroy', documentToDelete.value), {
            preserveScroll: true,
            onSuccess: () => {
                confirmingDocumentDeletion.value = false;
                documentToDelete.value = null;
            },
        });
    }
};

const closeModal = () => {
    confirmingDocumentDeletion.value = false;
    documentToDelete.value = null;
};

const formatDate = (val) => {
    if (!val) return '';
    return String(val).split('T')[0];
};

const parseLocalDate = (dateStr) => {
    if (!dateStr) return null;
    const [year, month, day] = dateStr.split('-');
    return new Date(year, month - 1, day);
};

const calculateWorkingDays = (startStr, endStr) => {
    const start = parseLocalDate(startStr);
    const end = parseLocalDate(endStr);
    if (!start || !end || start > end) return 0;

    let count = 0;
    let current = new Date(start);
    while (current <= end) {
        const dayOfWeek = current.getDay();
        if (dayOfWeek !== 0 && dayOfWeek !== 6) { // 0 = Sunday, 6 = Saturday
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    return count;
};

const calculateCalendarDays = (startStr, endStr) => {
    const start = parseLocalDate(startStr);
    const end = parseLocalDate(endStr);
    if (!start || !end || start > end) return 0;
    
    // Calculate difference in milliseconds, disregarding daylight saving offsets by zeroing hours
    const utc1 = Date.UTC(start.getFullYear(), start.getMonth(), start.getDate());
    const utc2 = Date.UTC(end.getFullYear(), end.getMonth(), end.getDate());
    
    return Math.floor((utc2 - utc1) / (1000 * 60 * 60 * 24)) + 1;
};

// Auto-calculate days when date_from, date_to, type, or vacation policy changes.
watch([() => form.date_from, () => form.date_to, () => form.type, () => form.vacation_config_id], ([newFrom, newTo, newType, newConfigId]) => {
    if (newFrom && newTo) {
        if (newType === 'salary_calculation') {
            form.days = calculateWorkingDays(newFrom, newTo);
        } else if (newType === 'vacation' && newConfigId) {
            const conf = props.vacationConfigs.find(c => c.id === newConfigId);
            if (conf) {
                let rules = conf.rules;
                if (typeof rules === 'string') rules = JSON.parse(rules);
                const unit = rules?.measure_unit || 'DD';
                if (unit === 'KD') {
                    form.days = calculateCalendarDays(newFrom, newTo);
                } else {
                    form.days = calculateWorkingDays(newFrom, newTo);
                }
            }
        }
    }
});

const documentTypes = [
    { value: 'hire', label: 'Pieņemšana darbā' },
    { value: 'salary_calculation', label: 'Algas aprēķins' },
    { value: 'vacation', label: 'Atvaļinājumi un prombūtnes' },
    { value: 'child_registration', label: 'Bērna reģistrācija' },
];

const getTypeLabel = (val) => {
    const found = documentTypes.find(t => t.value === val);
    return found ? found.label : val;
};

const getTypeName = (doc) => {
    let lbl = getTypeLabel(doc.type);
    if (doc.payload && doc.payload.vacation_config_id) {
        const conf = props.vacationConfigs.find(c => c.id === doc.payload.vacation_config_id);
        if (conf) lbl += ` - ${conf.name}`;
    }
    return lbl;
};

const getVacationMeasureUnit = (configId) => {
    if (!configId) return null;
    const conf = props.vacationConfigs.find(c => c.id === configId);
    if (!conf) return null;
    let rules = conf.rules;
    if (typeof rules === 'string') rules = JSON.parse(rules);
    return rules?.measure_unit || 'DD';
};

const calculatedDaysDetail = computed(() => {
    if (!form.date_from || !form.date_to || form.type !== 'vacation') return null;
    const kd = calculateCalendarDays(form.date_from, form.date_to);
    const dd = calculateWorkingDays(form.date_from, form.date_to);
    
    if (form.vacation_config_id) {
        const conf = props.vacationConfigs.find(c => c.id === form.vacation_config_id);
        if (conf) {
            let rules = conf.rules;
            if (typeof rules === 'string') rules = JSON.parse(rules);
            const unit = rules?.measure_unit || 'DD';
            if (unit === 'KD') return `Izvēlēts KD: ${kd} KD (t.sk. ${dd} DD)`;
            return `Izvēlēts DD: ${dd} DD (kopā ${kd} KD)`;
        }
    }
    return null;
});

// --- Calculation History Modals ---
const showingVacationLog = ref(false);
const showingSalaryLog = ref(false);

const openVacationLog = () => { showingVacationLog.value = true; };
const closeVacationLog = () => { showingVacationLog.value = false; };

const openSalaryLog = () => { showingSalaryLog.value = true; };
const closeSalaryLog = () => { showingSalaryLog.value = false; };
</script>

<template>
    <Head title="Simulator" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Algu un atvaļinājumu simulators
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                
                <!-- Employee Header Stats -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg p-6 text-white flex flex-col justify-center">
                        <span class="text-sm uppercase tracking-wider font-semibold opacity-80">Darbinieks</span>
                        <h3 class="text-2xl font-bold mt-1">{{ employee.vards }} {{ employee.uzvards }}</h3>
                        <p class="text-indigo-100 mt-2">{{ employee.amats }} • {{ employee.nodala }}</p>
                        <p v-if="hasHireDocument" class="text-xs mt-1 opacity-70">Sākuma datums: {{ formatDate(employee.sakdatums) }}</p>
                        <p v-else class="text-xs mt-1 text-yellow-300 opacity-90">⚠️ Nav reģistrēts 'Pieņemšana darbā'</p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-lg p-6 border-l-4 border-green-500 relative flex flex-col justify-center">
                        <button @click="openVacationLog" class="absolute top-4 right-4 text-gray-400 hover:text-green-600 transition" title="Skatīt aprēķina vēsturi">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </button>
                        <span class="text-sm uppercase tracking-wider font-semibold text-gray-500">Uzkrātais atlikums</span>
                        <div class="flex items-baseline mt-2">
                            <h3 class="text-3xl font-black text-gray-900">{{ stats.vacationBalanceDD }}</h3>
                            <span class="ml-1.5 text-base text-gray-500 font-medium tracking-wide">DD</span>
                            <span class="text-3xl text-gray-300 font-light mx-3">/</span>
                            <h3 class="text-3xl font-black text-gray-900">{{ stats.vacationBalanceKD }}</h3>
                            <span class="ml-1.5 text-base text-gray-500 font-medium tracking-wide">KD</span>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-lg p-6 border-l-4 border-blue-500 relative flex flex-col justify-center">
                        <button @click="openSalaryLog" class="absolute top-4 right-4 text-gray-400 hover:text-blue-600 transition" title="Skatīt aprēķina vēsturi">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </button>
                        <span class="text-sm uppercase tracking-wider font-semibold text-gray-500">Vidējā izpeļņa</span>
                        <div class="flex items-baseline mt-2">
                            <h3 class="text-4xl font-black text-gray-900">{{ stats.averageSalary }}</h3>
                            <span class="ml-2 text-lg text-gray-500 font-medium">€/dienā</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Pēdējo 6 mēnešu aprēķins</p>
                    </div>
                </div>

                <!-- Action Panel -->
                <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Dokumenta reģistrācija</h3>
                        <PrimaryButton @click="isFormOpen ? (isFormOpen = false) : openCreate()">
                            {{ isFormOpen ? 'Aizvērt' : '+ Pievienot' }}
                        </PrimaryButton>
                    </div>
                    
                    <transition enter-active-class="transition ease-out duration-200" enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                        <div v-if="isFormOpen" class="bg-gray-50 p-6 rounded-xl mt-4 border border-gray-200">
                            <form @submit.prevent="submitForm" class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel for="type" value="Dokumenta Tips" />
                                        <select id="type" v-model="form.type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            <option v-for="type in documentTypes" :key="type.value" :value="type.value">
                                                {{ type.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div v-if="form.type === 'vacation'">
                                        <InputLabel for="vacation_config_id" value="Atvaļinājuma Politika" />
                                        <select id="vacation_config_id" v-model="form.vacation_config_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            <option v-for="config in vacationConfigs" :key="config.id" :value="config.id">
                                                {{ config.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div v-if="form.type !== 'child_registration'">
                                        <InputLabel for="date_from" value="Datums No" />
                                        <TextInput id="date_from" type="date" class="mt-1 block w-full" v-model="form.date_from" required />
                                    </div>
                                    
                                    <div v-if="form.type !== 'child_registration'">
                                        <InputLabel for="date_to" value="Datums Līdz" />
                                        <TextInput id="date_to" type="date" class="mt-1 block w-full" v-model="form.date_to" />
                                    </div>

                                    <div v-if="form.type !== 'child_registration'">
                                        <InputLabel for="days" value="Dienas (Skaits)" />
                                        <div class="relative">
                                            <TextInput id="days" type="number" step="0.5" class="mt-1 block w-full" v-model="form.days" />
                                            <div v-if="calculatedDaysDetail" class="absolute -bottom-5 left-0 text-[11px] text-gray-500 font-medium whitespace-nowrap">
                                                {{ calculatedDaysDetail }}
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div v-if="form.type === 'child_registration'">
                                        <InputLabel for="child_dob" value="Bērna dzimšanas datums" />
                                        <TextInput id="child_dob" type="date" class="mt-1 block w-full" v-model="form.payload.child_dob" required />
                                    </div>
                                    
                                    <div v-if="form.type === 'child_registration'" class="flex items-center mt-6">
                                        <input id="is_disabled" type="checkbox" v-model="form.payload.is_disabled" class="w-4 h-4 text-indigo-600 bg-gray-100 border-gray-300 rounded focus:ring-indigo-500 focus:ring-2">
                                        <label for="is_disabled" class="ml-2 text-sm font-medium text-gray-900">Bērns ar invaliditāti</label>
                                    </div>

                                    <div v-if="form.type === 'salary_calculation'">
                                        <InputLabel for="amount" value="Summa (€) - Bruto alga" />
                                        <TextInput id="amount" type="number" step="0.01" class="mt-1 block w-full" v-model="form.amount" />
                                    </div>
                                </div>
                                <div class="flex items-center justify-end mt-4">
                                    <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                        Saglabāt
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    </transition>
                </div>

                <!-- Document Stream -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <h3 class="font-bold text-gray-800">Vēsture (Dokumentu plūsma)</h3>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="doc in documents" :key="doc.id" class="p-6 hover:bg-gray-50 transition duration-150 flex items-start space-x-4">
                            <!-- Icon -->
                            <div class="flex-shrink-0">
                                <span class="inline-flex items-center justify-center h-10 w-10 rounded-full" 
                                      :class="{
                                          'bg-blue-100 text-blue-600': doc.type === 'salary_calculation',
                                          'bg-purple-100 text-purple-600': doc.type === 'vacation',
                                          'bg-green-100 text-green-600': doc.type === 'child_registration',
                                          'bg-gray-100 text-gray-600': !['salary_calculation', 'vacation', 'child_registration'].includes(doc.type)
                                      }">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </span>
                            </div>
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 pb-1">
                                    {{ getTypeName(doc) }}
                                </p>
                                <div class="flex text-sm text-gray-500 space-x-4">
                                    <span v-if="doc.type !== 'child_registration' && doc.date_from">No: <strong class="text-gray-700">{{ formatDate(doc.date_from) }}</strong></span>
                                    <span v-if="doc.type !== 'child_registration' && doc.date_to">Līdz: <strong class="text-gray-700">{{ formatDate(doc.date_to) }}</strong></span>
                                    
                                    <span v-if="doc.type === 'child_registration'">Dzimšanas datums: <strong class="text-gray-700">{{ formatDate((typeof doc.payload === 'string' ? JSON.parse(doc.payload) : doc.payload)?.child_dob) }}</strong></span>
                                    <span v-if="doc.type === 'child_registration' && (typeof doc.payload === 'string' ? JSON.parse(doc.payload) : doc.payload)?.is_disabled" class="text-red-600 font-semibold text-xs ml-2 uppercase">(Invaliditāte)</span>
                                    
                                    <span v-if="doc.type !== 'child_registration' && doc.days">
                                        Dienas: <strong class="text-gray-700">{{ doc.days }}</strong>
                                        <span v-if="doc.type === 'vacation' && getVacationMeasureUnit(doc.payload?.vacation_config_id)" class="ml-1.5 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider" :class="getVacationMeasureUnit(doc.payload?.vacation_config_id) === 'KD' ? 'bg-orange-100 text-orange-700' : 'bg-teal-100 text-teal-700'">
                                            {{ getVacationMeasureUnit(doc.payload?.vacation_config_id) }}
                                        </span>
                                        <span v-if="doc.type === 'vacation' && doc.date_to" class="ml-1.5 text-[11px] text-gray-500 font-medium">
                                            ({{ calculateWorkingDays(doc.date_from, doc.date_to) }} DD / {{ calculateCalendarDays(doc.date_from, doc.date_to) }} KD)
                                        </span>
                                    </span>
                                    <span v-if="doc.amount" class="text-indigo-600 font-semibold">{{ doc.amount }} €</span>
                                </div>
                            </div>
                            <!-- Date badge & Actions -->
                            <div class="flex flex-col items-end space-y-2">
                                <span class="text-xs text-gray-400">
                                    Reģistrēts: {{ formatDate(doc.created_at) }}
                                </span>
                                <div class="flex space-x-2">
                                    <button @click="openEdit(doc)" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Rediģēt</button>
                                    <button @click="confirmDeletion(doc.id)" class="text-red-600 hover:text-red-900 text-sm font-medium">Dzēst</button>
                                </div>
                            </div>
                        </li>
                        <li v-if="documents.length === 0" class="p-6 text-center text-gray-500">
                            Nav reģistrētu dokumentu.
                        </li>
                    </ul>
                </div>

                <Modal :show="confirmingDocumentDeletion" @close="closeModal">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Vai tiešām vēlaties dzēst šo dokumentu?
                        </h2>

                        <p class="mt-1 text-sm text-gray-600">
                            Šī darbība ir neatgriezeniska. Tieks pārskaitīti visi saistītie atvaļinājumu atlikumi.
                        </p>

                        <div class="mt-6 flex justify-end">
                            <SecondaryButton @click="closeModal"> Atcelt </SecondaryButton>

                            <PrimaryButton
                                class="ms-3 bg-red-600 hover:bg-red-500 focus:bg-red-700"
                                @click="deleteDoc"
                            >
                                Dzēst dokumentu
                            </PrimaryButton>
                        </div>
                    </div>
                </Modal>

                <!-- Vacation Calculation Log Modal -->
                <Modal :show="showingVacationLog" @close="closeVacationLog" maxWidth="2xl">
                    <div class="p-6">
                        <div class="flex justify-between items-center border-b border-gray-100 pb-4 mb-4">
                            <h2 class="text-xl font-bold text-gray-800">
                                Atvaļinājuma bilances aprēķins
                            </h2>
                            <button @click="closeVacationLog" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
                        </div>

                        <div class="text-[14px] text-gray-700 space-y-2 max-h-96 overflow-y-auto w-full leading-relaxed pr-2">
                            <div v-for="(log, idx) in stats.vacationBalanceLog" :key="idx" class="border-b border-gray-50 pb-2 last:border-0 last:pb-0">
                                {{ log }}
                            </div>
                            <div v-if="stats.vacationBalanceLog.length === 0" class="text-gray-500 italic">
                                Nav datu.
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <PrimaryButton @click="closeVacationLog"> Aizvērt </PrimaryButton>
                        </div>
                    </div>
                </Modal>

                <!-- Salary Calculation Log Modal -->
                <Modal :show="showingSalaryLog" @close="closeSalaryLog" maxWidth="2xl">
                    <div class="p-6">
                        <div class="flex justify-between items-center border-b border-gray-100 pb-4 mb-4">
                            <h2 class="text-xl font-bold text-gray-800">
                                Vidējās dienas izpeļņas aprēķins
                            </h2>
                            <button @click="closeSalaryLog" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
                        </div>

                        <div class="text-[14px] text-gray-700 space-y-2 max-h-96 overflow-y-auto w-full leading-relaxed pr-2">
                            <div v-for="(log, idx) in stats.averageSalaryLog" :key="idx" class="border-b border-gray-50 pb-2 last:border-0 last:pb-0">
                                {{ log }}
                            </div>
                            <div v-if="stats.averageSalaryLog.length === 0" class="text-gray-500 italic">
                                Nav datu.
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <PrimaryButton @click="closeSalaryLog"> Aizvērt </PrimaryButton>
                        </div>
                    </div>
                </Modal>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
