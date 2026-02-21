<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    configs: Array,
});

const isFormOpen = ref(false);
const editMode = ref(false);

const form = useForm({
    id: null,
    tip: '',
    name: '',
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
    isFormOpen.value = true;
};

const openEdit = (config) => {
    editMode.value = true;
    form.id = config.id;
    form.tip = config.tip;
    form.name = config.name;
    form.is_accruable = Boolean(config.is_accruable);
    form.norm_days = config.norm_days;
    // ensure parsing rules if it's string (handled natively usually)
    let parsedRules = typeof config.rules === 'string' ? JSON.parse(config.rules) : config.rules;
    form.rules = parsedRules || {
        measure_unit: 'DD',
        financial_formula: 'unpaid',
        shifts_working_year: false,
    };
    isFormOpen.value = true;
};

const submitForm = () => {
    if (editMode.value) {
        form.put(route('policies.update', form.id), {
            onSuccess: () => { isFormOpen.value = false; form.reset(); }
        });
    } else {
        form.post(route('policies.store'), {
            onSuccess: () => { isFormOpen.value = false; form.reset(); }
        });
    }
};
</script>

<template>
    <Head title="Policies" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Atvaļinājumu Politiku Konfigurators
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                
                <div class="flex justify-end">
                    <PrimaryButton @click="openCreate">+ Jauna Politika</PrimaryButton>
                </div>

                <div v-if="isFormOpen" class="bg-white rounded-2xl shadow p-6 border border-gray-100">
                    <form @submit.prevent="submitForm" class="space-y-4">
                        <h3 class="text-lg font-bold mb-4">{{ editMode ? 'Rediģēt politiku' : 'Izveidot politiku' }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="name" value="Nosaukums" />
                                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required />
                            </div>
                            
                            <div>
                                <InputLabel for="tip" value="Tips (Kods)" />
                                <TextInput id="tip" type="number" class="mt-1 block w-full" v-model="form.tip" required />
                            </div>

                            <div class="flex items-center mt-4">
                                <input id="is_accruable" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" v-model="form.is_accruable" />
                                <label for="is_accruable" class="ml-2 block text-sm text-gray-900">Vai uzkrājams? (Accrual enabled)</label>
                            </div>

                            <div v-if="form.is_accruable">
                                <InputLabel for="norm_days" value="Norma gadā (dienās)" />
                                <TextInput id="norm_days" type="number" step="0.5" class="mt-1 block w-full" v-model="form.norm_days" />
                            </div>

                            <!-- Rules JSON GUI -->
                            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4 border-t pt-4 mt-2">
                                <div>
                                    <InputLabel value="Mērvienība" />
                                    <select v-model="form.rules.measure_unit" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="DD">DD (Darba dienas)</option>
                                        <option value="KD">KD (Kalendārās dienas)</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Finanšu Formula" />
                                    <select v-model="form.rules.financial_formula" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="average_salary">Vidējā izpeļņa</option>
                                        <option value="base_salary">Saglabāta mēnešalga</option>
                                        <option value="unpaid">Bez atalgojuma (0.00)</option>
                                    </select>
                                </div>
                                <div class="flex items-center mt-6">
                                    <input type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" v-model="form.rules.shifts_working_year" />
                                    <label class="ml-2 block text-sm text-gray-900">Pagarina darba gadu? (Shift base date)</label>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end mt-4 space-x-3">
                            <SecondaryButton @click="isFormOpen = false">Atcelt</SecondaryButton>
                            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                Saglabāt
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="config in configs" :key="config.id" class="bg-white rounded-2xl shadow p-6 border-t-4 hover:shadow-lg transition cursor-pointer" :class="config.is_accruable ? 'border-green-500' : 'border-blue-500'" @click="openEdit(config)">
                        <div class="flex justify-between items-start">
                            <h3 class="text-lg font-bold text-gray-900">{{ config.name }}</h3>
                            <span class="text-xs bg-gray-100 text-gray-600 py-1 px-2 rounded-full font-mono">Tips: {{ config.tip }}</span>
                        </div>
                        <div class="mt-4 space-y-2 text-sm text-gray-600">
                            <p><strong>Uzkrājums:</strong> <span :class="config.is_accruable ? 'text-green-600':'text-gray-500'">{{ config.is_accruable ? 'Jā (' + config.norm_days + ' d/gadā)' : 'Nē' }}</span></p>
                            <p><strong>Formula:</strong> {{ (typeof config.rules === 'string' ? JSON.parse(config.rules) : config.rules)?.financial_formula }}</p>
                            <p><strong>Pārceļ dzim.gadu:</strong> {{ (typeof config.rules === 'string' ? JSON.parse(config.rules) : config.rules)?.shifts_working_year ? 'Jā' : 'Nē' }}</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
