import CursorPager from '@/Components/CursorPager';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GlassSegmentedControl from '@/Components/GlassSegmentedControl';
import FinancialStatusBadge from '@/Components/FinancialStatusBadge';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import HelpTooltip from '@/Components/HelpTooltip';
import { workflows } from '@/Content/workflows';
import { financialHelp } from '@/Content/financialHelp';
import { formatUsd, moneySign } from '@/Utils/money';

function Difference({ value }) {
  const mismatch = moneySign(value) !== null && moneySign(value) !== 0;
  return <><span className={`money ${mismatch ? 'font-bold text-red-800 dark:text-red-200' : ''}`}>{formatUsd(value)}</span>{mismatch && <span className="block text-sm">Descuadre por revisar</span>}</>;
}
function Payments({ title, data }) {
  return <section><h3 className="font-semibold">{title}</h3><p>{data.status ?? 'Pendiente'}</p>
    <p className="money">Confirmado: {formatUsd(data.received)}</p>
    {Object.hasOwn(data, 'expected') && <p>Esperado: <span className="money">{formatUsd(data.expected)}</span><HelpTooltip {...financialHelp.expectedFiea} formula={data.expected_rule ?? 'Regla pendiente de validación'} /></p>}
    {Object.hasOwn(data, 'coverage') && <p className="money">Cobertura: {formatUsd(data.coverage)}</p>}
  </section>;
}
export default function AccountingControl({ years = [], filters = {}, rows = [], group_totals = [], summary = {}, next_cursor = null }) {
  const userId = usePage().props.auth.user.id;
  const indexUrl = route('accounting.control');
  return <AuthenticatedLayout header={<h1 className="text-3xl font-semibold">Control de ingresos y conciliación</h1>}>
    <Head title="Control de ingresos" />
    <div className="space-y-5">
      <WorkflowGuideCard key={`${userId}:treasury`} guideKey="treasury" userId={userId} title="Dos tramos de tesorería" steps={workflows.treasury} />
      <GlassSegmentedControl label="Año" options={years.map((year) => ({ value: String(year), label: String(year) }))} value={String(filters.year)}
        onChange={(year) => router.get(indexUrl, { year, basis: filters.basis }, { preserveScroll: true })} />
      <GlassSegmentedControl label="Base de cálculo" options={[
        { value: 'case_lifetime', label: 'Vida del expediente' },
        { value: 'period_cash', label: 'Cobros del año' },
      ]} value={filters.basis ?? 'case_lifetime'}
        onChange={(basis) => router.get(indexUrl, { year: filters.year, basis }, { preserveScroll: true })} />
      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Totales del filtro completo">
        {[['income','Ingreso real IC + MAT'],['operating_total','Gasto Final'],['difference','Diferencia ingreso y gasto'],['credit','Crédito disponible']].map(([key,label]) => <div key={key} className="glass-panel p-4"><p>{label}</p><p className="money mt-2 text-xl font-semibold">{formatUsd(summary[key])}</p></div>)}
      </section>
      <section className="glass-panel p-4"><h2 className="font-semibold">Consolidados de los grupos visibles por proyecto y equipo</h2>
        <p className="mt-1 text-sm">Cada grupo se cuenta una vez; los importes no se vuelven a sumar por invoice.</p>
        <dl className="mt-3 space-y-3">{group_totals.map((group) => <div key={group.group_key} className="glass-tile p-3">
          <dt className="font-semibold">Proyecto {group.project_id} · Equipo {group.team_id}</dt><dd className="mt-1">Ingreso: {formatUsd(group.income)} · Gasto Final: {formatUsd(group.operating_total)} · Crédito del equipo (compartido): {formatUsd(group.credit)}</dd>
        </div>)}</dl>
      </section>
      <WorkflowGuideCard key={`${userId}:accounting`} guideKey="accounting" userId={userId} title="Revisión de descuadres" steps={workflows.accounting} />
      <div className="glass-panel p-3 financial-scroll" tabIndex={0} role="region" aria-label="Control anual de expedientes">
        <table className="financial-table"><caption className="p-3 text-left">Expedientes del año {filters.year}; montos en USD</caption>
          <thead><tr><th scope="col">Proyecto / códigos</th><th scope="col">Estado</th><th scope="col">Operativo Final</th><th scope="col">Conta</th><th scope="col">Diferencia<HelpTooltip {...financialHelp.accountingDifference} /></th><th scope="col">Balance / crédito</th><th scope="col">Movimientos y seguimiento</th></tr></thead>
          <tbody>{rows.map((row) => <tr key={row.billing_case_id}>
            <th scope="row" className="min-w-56 text-left"><p>{row.project_code} · {row.project_name}</p><p className="text-sm font-normal">Equipo {row.team_id} · {row.invoice_type}</p>
              <ul className="mt-2 space-y-1">{row.invoice_codes.map((code) => <li key={code} className="text-sm">{code}</li>)}</ul>
            </th>
            <td><FinancialStatusBadge status={row.lifecycle_status} /></td><td className="money">{formatUsd(row.operating_total)}</td><td className="money">{formatUsd(row.accounting_total)}</td><td><Difference value={row.difference} /></td>
            <td><p>Documental: <span className="money">{formatUsd(row.balance_due)}</span><HelpTooltip {...financialHelp.balanceDue} /></p><p>Crédito del equipo (compartido): <span className="money">{formatUsd(row.credit)}</span><HelpTooltip {...financialHelp.credit} /></p></td>
            <td className="min-w-64"><details><summary className="cursor-pointer rounded-lg px-2 py-3 focus-visible:outline">Ver tramos, notas y acciones</summary>
              <div className="space-y-4 py-3"><Payments title="Capítulo → EIA USA" data={row.eia} /><Payments title="EIA USA → FIEA Ecuador" data={row.fiea} />
                 <p className="text-sm text-app-muted">Los movimientos detallados se gestionan desde el expediente.</p>
              </div>
            </details></td>
          </tr>)}
          {rows.length === 0 && <tr><td colSpan={7} className="p-8 text-center">Sin expedientes para estos filtros. <Link href={indexUrl} className="underline">Limpiar filtros</Link></td></tr>}
          </tbody>
        </table>
      </div>
      <CursorPager label="expedientes" page={{ data: rows, next_page_url: next_cursor ? route('accounting.control', { ...filters, cursor: next_cursor }) : null }} firstPageUrl={filters.cursor ? route('accounting.control', { year: filters.year, basis: filters.basis }) : null} />
    </div>
  </AuthenticatedLayout>;
}
