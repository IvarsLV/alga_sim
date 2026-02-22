<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    employee: Object,
    documents: Array,
    vacationConfigs: Array,
    hasHireDocument: Boolean,
    balanceTable: Array,
});

// ─── State ───
const expandedRows = ref({});
const showAlgorithm = ref({});
const showDocForm = ref(false);
const editingDoc = ref(null);

const form = useForm({
    employee_id: props.employee.id,
    type: 'vacation',
    date_from: '',
    date_to: '',
    days: null,
    payload: {},
    vacation_config_id: null,
});

// ─── Computed ───
const nonSalaryDocs = computed(() =>
    props.documents.filter(d => d.type !== 'salary_calculation')
);

const docTypes = [
    { value: 'hire', label: 'Pieņemšana darbā' },
    { value: 'vacation', label: 'Atvaļinājums' },
    { value: 'child_registration', label: 'Bērna reģistrācija' },
    { value: 'unpaid_leave', label: 'Bezalgas atvaļinājums' },
    { value: 'study_leave', label: 'Mācību atvaļinājums' },
    { value: 'donor_day', label: 'Donora diena' },
];

const isVacationType = computed(() =>
    ['vacation', 'unpaid_leave', 'study_leave', 'donor_day'].includes(form.type)
);

// ─── Methods ───
function toggleRow(configId) {
    expandedRows.value[configId] = !expandedRows.value[configId];
}

function toggleAlgorithm(configId) {
    showAlgorithm.value[configId] = !showAlgorithm.value[configId];
}

function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return dt.toLocaleDateString('lv-LV', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function getDocTypeLabel(type) {
    const found = docTypes.find(t => t.value === type);
    return found ? found.label : type;
}

function getConfigName(doc) {
    const payload = typeof doc.payload === 'string' ? JSON.parse(doc.payload) : doc.payload;
    const configId = payload?.vacation_config_id;
    if (!configId) return '';
    const config = props.vacationConfigs.find(c => c.id === configId);
    return config ? config.name : '';
}

function openNewDoc() {
    editingDoc.value = null;
    form.reset();
    form.employee_id = props.employee.id;
    form.type = 'vacation';
    showDocForm.value = true;
}

function editDoc(doc) {
    editingDoc.value = doc;
    const payload = typeof doc.payload === 'string' ? JSON.parse(doc.payload) : doc.payload;
    form.employee_id = doc.employee_id;
    form.type = doc.type;
    form.date_from = doc.date_from ? doc.date_from.split('T')[0] : '';
    form.date_to = doc.date_to ? doc.date_to.split('T')[0] : '';
    form.days = doc.days;
    form.payload = payload || {};
    form.vacation_config_id = payload?.vacation_config_id || null;
    showDocForm.value = true;
}

function saveDoc() {
    if (editingDoc.value) {
        form.put(route('documents.update', editingDoc.value.id), {
            onSuccess: () => { showDocForm.value = false; editingDoc.value = null; },
        });
    } else {
        form.post(route('documents.store'), {
            onSuccess: () => { showDocForm.value = false; },
        });
    }
}

function deleteDoc(doc) {
    if (confirm('Dzēst šo dokumentu?')) {
        router.delete(route('documents.destroy', doc.id));
    }
}

function getLawBadgeColor(lawRef) {
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
}
</script>

<template>
    <Head title="Atvaļinājumu simulators" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="page-title">Atvaļinājumu bilances simulators</h2>
        </template>

        <div class="simulator-container">
            <!-- Employee Info -->
            <section class="employee-card">
                <div class="employee-info">
                    <div class="employee-avatar">{{ employee.vards?.[0] }}{{ employee.uzvards?.[0] }}</div>
                    <div>
                        <h3>{{ employee.vards }} {{ employee.uzvards }}</h3>
                        <p>{{ employee.amats }} · {{ employee.nodala }}</p>
                        <p class="hire-date">Darba sākums: <strong>{{ formatDate(employee.sakdatums) }}</strong></p>
                    </div>
                </div>
            </section>

            <!-- ═══════════════════════════════════════════════════════════ -->
            <!-- BALANCE TABLE (Warehouse View)                            -->
            <!-- ═══════════════════════════════════════════════════════════ -->
            <section class="balance-section">
                <h2 class="section-title">
                    <span class="icon">📊</span> Atvaļinājumu bilances
                </h2>

                <table class="balance-table">
                    <thead>
                        <tr>
                            <th class="col-type">Atvaļinājuma veids</th>
                            <th class="col-law">Likums</th>
                            <th class="col-num">Uzkrāts (DD)</th>
                            <th class="col-num">Izmantots (DD)</th>
                            <th class="col-num col-balance">Atlikums (DD)</th>
                            <th class="col-num">Atlikums (KD)</th>
                            <th class="col-actions">Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in balanceTable" :key="row.config_id">
                            <tr class="balance-row" :class="{ 'row-has-balance': row.balance_dd > 0, 'row-expanded': expandedRows[row.config_id] }">
                                <td class="col-type">
                                    <button class="expand-btn" @click="toggleRow(row.config_id)">
                                        {{ expandedRows[row.config_id] ? '▼' : '▶' }}
                                    </button>
                                    <span class="type-name">{{ row.config_name }}</span>
                                </td>
                                <td class="col-law">
                                    <span class="law-badge" :style="{ backgroundColor: getLawBadgeColor(row.rules?.law_reference) }">
                                        {{ row.rules?.law_reference || '—' }}
                                    </span>
                                </td>
                                <td class="col-num accrued">{{ row.accrued > 0 ? row.accrued.toFixed(2) : '—' }}</td>
                                <td class="col-num used">{{ row.used > 0 ? row.used.toFixed(2) : '—' }}</td>
                                <td class="col-num col-balance" :class="{ positive: row.balance_dd > 0, negative: row.balance_dd < 0 }">
                                    <strong>{{ row.balance_dd !== 0 ? row.balance_dd.toFixed(2) : '—' }}</strong>
                                </td>
                                <td class="col-num">{{ row.balance_kd !== 0 ? row.balance_kd.toFixed(2) : '—' }}</td>
                                <td class="col-actions">
                                    <button class="algo-btn" @click="toggleAlgorithm(row.config_id)" title="Skatīt algoritmu">
                                        🔬
                                    </button>
                                </td>
                            </tr>

                            <!-- Expanded: Transaction History (Stock Card) -->
                            <tr v-if="expandedRows[row.config_id]" class="detail-row">
                                <td :colspan="7">
                                    <div class="stock-card">
                                        <h4>📋 Darījumu vēsture ({{ row.config_name }})</h4>
                                        <p class="stock-description">{{ row.description }}</p>
                                        <table class="transactions-table" v-if="row.transactions && row.transactions.length > 0">
                                            <thead>
                                                <tr>
                                                    <th>Periods</th>
                                                    <th>Tips</th>
                                                    <th>Apraksts</th>
                                                    <th class="col-num">+Uzkrāts</th>
                                                    <th class="col-num">-Izmantots</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(t, idx) in row.transactions" :key="idx" 
                                                    :class="{ 'tx-accrual': t.transaction_type === 'accrual', 'tx-usage': t.transaction_type === 'usage' }">
                                                    <td>{{ formatDate(t.period_from) }} — {{ formatDate(t.period_to) }}</td>
                                                    <td>
                                                        <span :class="'tx-badge tx-' + t.transaction_type">
                                                            {{ t.transaction_type === 'accrual' ? 'Uzkrājums' : 'Izmantots' }}
                                                        </span>
                                                    </td>
                                                    <td class="desc-cell">{{ t.description }}</td>
                                                    <td class="col-num accrued">{{ t.transaction_type === 'accrual' ? (+t.days_dd).toFixed(2) : '' }}</td>
                                                    <td class="col-num used">{{ t.transaction_type === 'usage' ? Math.abs(t.days_dd).toFixed(2) : '' }}</td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="3"><strong>Kopā</strong></td>
                                                    <td class="col-num accrued"><strong>{{ row.accrued.toFixed(2) }}</strong></td>
                                                    <td class="col-num used"><strong>{{ row.used.toFixed(2) }}</strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        <p v-else class="no-transactions">Nav darījumu.</p>
                                    </div>
                                </td>
                            </tr>

                            <!-- Algorithm Panel -->
                            <tr v-if="showAlgorithm[row.config_id]" class="algo-row">
                                <td :colspan="7">
                                    <div class="algorithm-panel">
                                        <h4>🔬 Aprēķina algoritms</h4>
                                        <div class="algo-lines">
                                            <div v-for="(line, idx) in row.algorithm" :key="idx" 
                                                 class="algo-line" 
                                                 :class="{ 'algo-bold': line.startsWith('**'), 'algo-warning': line.startsWith('⚠️') }"
                                                 v-html="formatAlgoLine(line)">
                                            </div>
                                        </div>
                                        <div class="algo-rules">
                                            <h5>Konfigurācija:</h5>
                                            <div class="rule-grid">
                                                <span class="rule-label">Uzkrāšanas metode:</span>
                                                <span class="rule-value">{{ row.rules?.accrual_method || '—' }}</span>
                                                <span class="rule-label">Perioda tips:</span>
                                                <span class="rule-value">{{ row.rules?.period_type || '—' }}</span>
                                                <span class="rule-label">Nobīda darba gadu:</span>
                                                <span class="rule-value">{{ row.rules?.shifts_working_year ? 'Jā (>' + (row.rules?.shifts_working_year_threshold_weeks || 4) + ' ned.)' : 'Nē' }}</span>
                                                <span class="rule-label">Mērvienība:</span>
                                                <span class="rule-value">{{ row.rules?.measure_unit || 'DD' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>

            <!-- ═══════════════════════════════════════════════════════════ -->
            <!-- DOCUMENTS LIST                                            -->
            <!-- ═══════════════════════════════════════════════════════════ -->
            <section class="documents-section">
                <div class="section-header">
                    <h2 class="section-title"><span class="icon">📄</span> Dokumenti</h2>
                    <button class="btn-add" @click="openNewDoc">+ Pievienot dokumentu</button>
                </div>

                <table class="doc-table">
                    <thead>
                        <tr>
                            <th>Tips</th>
                            <th>Veids</th>
                            <th>No</th>
                            <th>Līdz</th>
                            <th>Dienas</th>
                            <th>Darbības</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="doc in nonSalaryDocs" :key="doc.id">
                            <td><span class="doc-type-badge">{{ getDocTypeLabel(doc.type) }}</span></td>
                            <td>{{ getConfigName(doc) }}</td>
                            <td>{{ formatDate(doc.date_from) }}</td>
                            <td>{{ formatDate(doc.date_to) }}</td>
                            <td>{{ doc.days || '—' }}</td>
                            <td>
                                <button class="btn-sm btn-edit" @click="editDoc(doc)">✏️</button>
                                <button class="btn-sm btn-del" @click="deleteDoc(doc)">🗑️</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ═══════════════════════════════════════════════════════════ -->
            <!-- DOCUMENT FORM MODAL                                       -->
            <!-- ═══════════════════════════════════════════════════════════ -->
            <div v-if="showDocForm" class="modal-overlay" @click.self="showDocForm = false">
                <div class="modal-content">
                    <h3>{{ editingDoc ? 'Rediģēt dokumentu' : 'Jauns dokuments' }}</h3>
                    <form @submit.prevent="saveDoc">
                        <div class="form-group">
                            <label>Dokumenta tips</label>
                            <select v-model="form.type">
                                <option v-for="dt in docTypes" :key="dt.value" :value="dt.value">{{ dt.label }}</option>
                            </select>
                        </div>

                        <div v-if="isVacationType" class="form-group">
                            <label>Atvaļinājuma veids</label>
                            <select v-model="form.vacation_config_id">
                                <option :value="null">— Izvēlieties —</option>
                                <option v-for="vc in vacationConfigs" :key="vc.id" :value="vc.id">{{ vc.name }}</option>
                            </select>
                        </div>

                        <div v-if="form.type !== 'child_registration'" class="form-row">
                            <div class="form-group">
                                <label>Datums no</label>
                                <input type="date" v-model="form.date_from" />
                            </div>
                            <div class="form-group">
                                <label>Datums līdz</label>
                                <input type="date" v-model="form.date_to" />
                            </div>
                        </div>

                        <div v-if="form.type === 'child_registration'" class="form-group">
                            <label>Bērna dzimšanas datums</label>
                            <input type="date" v-model="form.payload.child_dob" />
                        </div>
                        <div v-if="form.type === 'child_registration'" class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" v-model="form.payload.is_disabled" /> Bērns ar invaliditāti
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-cancel" @click="showDocForm = false">Atcelt</button>
                            <button type="submit" class="btn-save" :disabled="form.processing">Saglabāt</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
export default {
    methods: {
        formatAlgoLine(line) {
            // Bold markdown
            let result = line.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            return result;
        }
    }
};
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════ */
/* DESIGN SYSTEM                                         */
/* ═══════════════════════════════════════════════════════ */
.simulator-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
    font-family: 'Inter', system-ui, sans-serif;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
}

.section-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title .icon { font-size: 1.3rem; }

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

/* ─── Employee Card ─── */
.employee-card {
    background: linear-gradient(135deg, #1e40af, #3b82f6);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    color: white;
}
.employee-info {
    display: flex;
    align-items: center;
    gap: 16px;
}
.employee-avatar {
    width: 56px; height: 56px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.2rem;
}
.employee-info h3 { font-size: 1.3rem; margin: 0; }
.employee-info p { margin: 2px 0; opacity: 0.9; font-size: 0.9rem; }
.hire-date { opacity: 0.8; }

/* ─── Balance Table ─── */
.balance-section {
    background: white;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.balance-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.balance-table th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.balance-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
}

.balance-row { cursor: pointer; transition: background 0.15s; }
.balance-row:hover { background: #f8fafc; }
.row-has-balance { background: #f0f9ff; }
.row-expanded { background: #eff6ff; }

.col-num { text-align: right; font-variant-numeric: tabular-nums; }
.col-balance { font-size: 1em; }
.col-actions { text-align: center; width: 60px; }

.positive { color: #059669; }
.negative { color: #dc2626; }
.accrued { color: #2563eb; }
.used { color: #dc2626; }

.expand-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.75rem;
    color: #94a3b8;
    padding: 0 8px 0 0;
}

.type-name { font-weight: 500; }

.law-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

.algo-btn {
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    padding: 4px 8px;
    font-size: 1rem;
    transition: all 0.15s;
}
.algo-btn:hover { background: #f1f5f9; border-color: #cbd5e1; }

/* ─── Detail Row: Stock Card ─── */
.detail-row td { padding: 0; }
.stock-card {
    background: #f8fafc;
    padding: 20px 24px;
    border-left: 4px solid #3b82f6;
}
.stock-card h4 { margin: 0 0 8px; font-size: 1rem; color: #1e293b; }
.stock-description { font-size: 0.85rem; color: #64748b; margin-bottom: 12px; }

.transactions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    background: white;
    border-radius: 8px;
    overflow: hidden;
}
.transactions-table th {
    background: #f1f5f9;
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 0.78rem;
    text-transform: uppercase;
}
.transactions-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
.transactions-table tfoot td { border-top: 2px solid #e2e8f0; background: #f8fafc; }

.tx-accrual { background: #f0fdf4; }
.tx-usage { background: #fef2f2; }
.tx-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 600;
}
.tx-accrual .tx-badge, .tx-badge.tx-accrual { background: #dcfce7; color: #166534; }
.tx-usage .tx-badge, .tx-badge.tx-usage { background: #fecaca; color: #991b1b; }

.desc-cell { max-width: 400px; font-size: 0.83rem; color: #475569; }
.no-transactions { color: #94a3b8; font-style: italic; }

/* ─── Algorithm Panel ─── */
.algo-row td { padding: 0; }
.algorithm-panel {
    background: #fffbeb;
    padding: 20px 24px;
    border-left: 4px solid #f59e0b;
}
.algorithm-panel h4 { margin: 0 0 12px; font-size: 1rem; color: #92400e; }

.algo-lines { margin-bottom: 16px; }
.algo-line {
    padding: 4px 0;
    font-size: 0.88rem;
    color: #44403c;
    line-height: 1.5;
}
.algo-bold { font-weight: 700; color: #1c1917; }
.algo-warning { color: #c2410c; font-weight: 500; }

.algo-rules { border-top: 1px solid #fde68a; padding-top: 12px; }
.algo-rules h5 { margin: 0 0 8px; font-size: 0.85rem; color: #92400e; }
.rule-grid {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 4px 16px;
    font-size: 0.83rem;
}
.rule-label { color: #78716c; font-weight: 500; }
.rule-value { color: #1c1917; }

/* ─── Documents ─── */
.documents-section {
    background: white;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.doc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.doc-table th {
    background: #f8fafc;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.78rem;
    text-transform: uppercase;
}
.doc-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }

.doc-type-badge {
    display: inline-block;
    padding: 2px 10px;
    background: #e0e7ff;
    color: #3730a3;
    border-radius: 10px;
    font-size: 0.78rem;
    font-weight: 600;
}

.btn-add {
    background: #2563eb;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-add:hover { background: #1d4ed8; }

.btn-sm {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    font-size: 1rem;
    opacity: 0.6;
    transition: opacity 0.15s;
}
.btn-sm:hover { opacity: 1; }

/* ─── Modal ─── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    backdrop-filter: blur(4px);
}
.modal-content {
    background: white;
    border-radius: 16px;
    padding: 32px;
    width: 90%;
    max-width: 520px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-content h3 { margin: 0 0 20px; font-size: 1.2rem; }

.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: 4px;
}
.form-group input, .form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    box-sizing: border-box;
}
.form-group input:focus, .form-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.form-row { display: flex; gap: 16px; }
.form-row .form-group { flex: 1; }

.checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.checkbox-label input[type="checkbox"] { width: auto; }

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
}
.btn-cancel {
    background: #f1f5f9;
    color: #475569;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    cursor: pointer;
}
.btn-save {
    background: #2563eb;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}
.btn-save:hover { background: #1d4ed8; }
.btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
