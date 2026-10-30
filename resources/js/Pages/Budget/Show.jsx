import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DailyBudgetMatrix from '@/Components/DailyBudgetMatrix';
import FinancialSummary from '@/Components/FinancialSummary';
import BudgetDocument, { MasterMetadata } from '@/Components/BudgetDocument';
import GlassSegmentedControl from '@/Components/GlassSegmentedControl';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import { workflows } from '@/Content/workflows';
import { useUnsavedChangesGuard } from '@/Utils/useUnsavedChangesGuard';

function payload(budget, type, stage) {
  return { version: budget.version, invoice_type: type, stage,
    lines: budget.lines.map(({ id, unit_cost, quantity, receipt_number, cells }) => ({
      id, unit_cost, quantity, receipt_number: receipt_number ?? null,
      cells: cells.map(({ date, quantity: q, receipt_number: r }) => ({ date, quantity: q, receipt_number: r ?? null })),
    })),
  };
}
function budgetDifferences(localLines, serverLines) {
  const local = new Map(localLines.map((line) => [line.id, line]));
  const server = new Map(serverLines.map((line) => [line.id, line]));
  const result = [];
  for (const id of new Set([...local.keys(), ...server.keys()])) {
    const a = local.get(id), b = server.get(id);
    if (!a || !b) {
      result.push({ key: `${id}:row`, label: `Línea ${id}`, local: a ? 'Existe en tu edición' : 'No existe', server: b ? 'Existe en servidor' : 'Eliminada en servidor' });
      continue;
    }
    for (const field of ['unit_cost', 'quantity', 'receipt_number']) {
      if ((a[field] ?? '') !== (b[field] ?? '')) result.push({ key: `${id}:${field}`, label: `Línea ${id}, ${field}`, local: a[field] ?? 'Vacío', server: b[field] ?? 'Vacío' });
    }
    const ac = new Map(a.cells.map((cell) => [cell.date, cell]));
    const bc = new Map(b.cells.map((cell) => [cell.date, cell]));
    for (const date of new Set([...ac.keys(), ...bc.keys()])) {
      for (const field of ['quantity', 'receipt_number']) {
        const av = ac.get(date)?.[field], bv = bc.get(date)?.[field];
        if ((av ?? '') !== (bv ?? '')) result.push({ key: `${id}:${date}:${field}`, label: `Línea ${id}, ${date}, ${field}`, local: av ?? 'Vacío', server: bv ?? 'Vacío' });
      }
    }
  }
  return result;
}
export default function BudgetShow(props) {
  const userId = usePage().props.auth.user.id;
  return <BudgetEditor key={`${userId}:${props.tripPhase.id}:${props.invoice_type}:${props.stage}:${props.view}`} {...props} userId={userId} />;
}
function BudgetEditor({ tripPhase, invoice_type, stage, view, sheets, editors, budget, master, invoicePreview, can, urls, userId }) {
  const key = `Budget:${userId}:${tripPhase.id}:${invoice_type}:${stage}`;
  const form = useForm(key, payload(budget, invoice_type, stage));
  const guard = useUnsavedChangesGuard(form.isDirty);
  const conflict = form.data.version !== budget.version;
  const changeLine = (id, field, value) => form.setData('lines', form.data.lines.map((line) => line.id === id ? { ...line, [field]: value } : line));
  const changeCell = (id, date, field, value) => form.setData('lines', form.data.lines.map((line) => line.id === id ? {
    ...line, cells: line.cells.map((cell) => cell.date === date ? { ...cell, [field]: value } : cell),
  } : line));
  const displayedLines = budget.lines.map((line) => {
    const draft = form.data.lines.find((item) => item.id === line.id);
    return draft ? { ...line, ...draft, cells: line.cells.map((cell) => ({ ...cell, ...draft.cells.find((item) => item.date === cell.date) })) } : line;
  });
  const submit = (event) => {
    event.preventDefault();
    if (!can.update || conflict) return;
    form.patch(urls.update, {
      preserveScroll: true,
      onSuccess: (page) => {
        const next = payload(page.props.budget, invoice_type, stage);
        form.setData(next); form.setDefaults(next);
      },
      onError: () => requestAnimationFrame(() => document.getElementById('budget-errors')?.focus()),
    });
  };
  const resetFromServer = () => { const next = payload(budget, invoice_type, stage); form.setData(next); form.setDefaults(next); form.clearErrors(); };
  const differences = conflict ? budgetDifferences(form.data.lines, budget.lines) : [];
  return <AuthenticatedLayout header={<h1 className="text-3xl font-semibold">Presupuesto · {tripPhase.label}</h1>}>
    <Head title="Presupuesto" />
    <div className="space-y-5">
      <WorkflowGuideCard key={`${userId}:budget`} guideKey="budget" userId={userId} title="Del presupuesto al cierre" steps={workflows.budget} />
      <GlassSegmentedControl label="Documento" options={sheets} value={view} onChange={(value) => {
        const selected = sheets.find((item) => item.value === value);
        if (selected) guard.request(() => router.get(selected.href));
      }} />
      {view === 'master' ? <MasterMetadata metadata={master?.metadata} editLinks={master?.edit_links} /> : view === 'draft' ? <>
        <GlassSegmentedControl label="Matriz editable" options={editors} value={`${invoice_type}:${stage}`} onChange={(value) => {
          const selected = editors.find((item) => item.value === value);
          if (selected) guard.request(() => router.get(selected.href));
        }} />
        <form onSubmit={submit} className="glass-panel p-4" aria-busy={form.processing}>
          <p role="status" className="mb-3">{form.processing ? 'Guardando…' : form.isDirty ? 'Cambios pendientes de guardar' : 'Versión guardada'} · versión {form.data.version}</p>
          {!can.update && <p className="mb-3">Esta etapa es de solo lectura para tu acceso o su estado actual.</p>}
          {(conflict || form.errors.version) && <div className="mb-3 rounded-xl border border-amber-600 p-3" role="alert">
            Hay una versión distinta. Conservamos tu edición; compárala antes de reemplazarla.
            <details className="mt-3"><summary className="cursor-pointer py-2">Comparar edición local y servidor</summary>
              <ul className="space-y-2">{differences.map((item) => <li key={item.key}>
                <strong>{item.label}</strong>: tu edición «{item.local}»; servidor «{item.server}».
              </li>)}</ul>
            </details>
            <button type="button" className="glass-button ml-2 px-3" onClick={() => guard.request(resetFromServer)}>Usar versión recibida y descartar edición</button>
          </div>}
          {Object.keys(form.errors).length > 0 && <div id="budget-errors" tabIndex={-1} role="alert" className="mb-4 rounded-xl border border-red-600 p-3">
            <p>Revisa los campos indicados:</p><ul className="list-inside list-disc">{Object.entries(form.errors).map(([field, message]) => <li key={field}>{message}</li>)}</ul>
          </div>}
          <DailyBudgetMatrix days={budget.days} lines={displayedLines} invoiceType={invoice_type} stage={stage}
            editable={can.update && !form.processing && !conflict} dirty={form.isDirty} errors={form.errors} onLineChange={changeLine} onCellChange={changeCell} />
          <button type="submit" className="glass-button mt-4 px-5" disabled={!can.update || form.processing || !form.isDirty || conflict}>Guardar matriz</button>
        </form>
        <FinancialSummary totals={budget.totals} dirty={form.isDirty} />
      </> : <BudgetDocument preview={invoicePreview} />}
    </div>
    <FinancialActionSheet {...guard.dialogProps} />
  </AuthenticatedLayout>;
}
