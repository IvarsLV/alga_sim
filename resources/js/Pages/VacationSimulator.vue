<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    employee: Object,
    documents: Array,
    vacationConfigs: Array,
    stats: Object,
});

const isFormOpen = ref(false);

const form = useForm({
    employee_id: props.employee.id,
    type: 'vacation',
    date_from: '',
    date_to: '',
    days: '',
    amount: '',
    vacation_config_id: '',
    payload: {},
});

const submitForm = () => {
    form.post(route('documents.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isFormOpen.value = false;
            form.reset('date_from', 'date_to', 'days', 'amount', 'vacation_config_id');
        },
    });
};

const documentTypes = [
    { value: 'hire', label: 'Pieņemšana darbā' },
    { value: 'salary_calculation', label: 'Algas aprēķins' },
    { value: 'vacation', label: 'Atvaļinājums' },
    { value: 'study_leave', label: 'Mācību atvaļinājums' },
    { value: 'unpaid_leave', label: 'Bezalgas atvaļinājums' },
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
                        <p class="text-xs mt-1opacity-70">Sākuma datums: {{ employee.sakdatums }}</p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-lg p-6 border-l-4 border-green-500 flex flex-col justify-center">
                        <span class="text-sm uppercase tracking-wider font-semibold text-gray-500">Uzkrātais atlikums</span>
                        <div class="flex items-baseline mt-2">
                            <h3 class="text-4xl font-black text-gray-900">{{ stats.vacationBalance }}</h3>
                            <span class="ml-2 text-lg text-gray-500 font-medium">dienas</span>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-lg p-6 border-l-4 border-blue-500 flex flex-col justify-center">
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
                        <h3 class="text-lg font-bold text-gray-800">Jauna dokumenta reģistrācija</h3>
                        <PrimaryButton @click="isFormOpen = !isFormOpen">
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

                                    <div v-if="['vacation', 'study_leave', 'unpaid_leave'].includes(form.type)">
                                        <InputLabel for="vacation_config_id" value="Atvaļinājuma Politika" />
                                        <select id="vacation_config_id" v-model="form.vacation_config_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            <option v-for="config in vacationConfigs" :key="config.id" :value="config.id">
                                                {{ config.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <InputLabel for="date_from" value="Datums No" />
                                        <TextInput id="date_from" type="date" class="mt-1 block w-full" v-model="form.date_from" required />
                                    </div>
                                    
                                    <div>
                                        <InputLabel for="date_to" value="Datums Līdz" />
                                        <TextInput id="date_to" type="date" class="mt-1 block w-full" v-model="form.date_to" />
                                    </div>

                                    <div>
                                        <InputLabel for="days" value="Dienas (Skaits)" />
                                        <TextInput id="days" type="number" step="0.5" class="mt-1 block w-full" v-model="form.days" />
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
                                    <span v-if="doc.date_from">No: <strong class="text-gray-700">{{ doc.date_from }}</strong></span>
                                    <span v-if="doc.date_to">Līdz: <strong class="text-gray-700">{{ doc.date_to }}</strong></span>
                                    <span v-if="doc.days">Dienas: <strong class="text-gray-700">{{ doc.days }}</strong></span>
                                    <span v-if="doc.amount" class="text-indigo-600 font-semibold">{{ doc.amount }} €</span>
                                </div>
                            </div>
                            <!-- Date badge -->
                            <div class="text-xs text-gray-400">
                                Reģistrēts: {{ new Date(doc.created_at).toLocaleDateString() }}
                            </div>
                        </li>
                        <li v-if="documents.length === 0" class="p-6 text-center text-gray-500">
                            Nav reģistrētu dokumentu.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
