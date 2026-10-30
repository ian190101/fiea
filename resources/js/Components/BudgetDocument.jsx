import { Link } from '@inertiajs/react';
import { formatUsd } from '@/Utils/money';
import FinancialSummary from '@/Components/FinancialSummary';
const metadataLabels = {
  team_name: 'TEAM', project_name: 'PROJECT', volunteer_count: 'NUMBER OF VOLUNTEERS', staff_count: 'NUMBER OF EIA STAFF',
  starts_on: 'START', ends_on: 'FINISH', invoice_name: 'INVOICE NAME', address: 'ADDRESS', phone: 'PHONE #', email: 'EMAIL',
  invoice_code: 'INVOICE #', project_code: 'CODE', document_date: 'DATE',
};
export function MasterMetadata({ metadata = {}, editLinks = [] }) {
  return <section className="glass-panel p-5"><h2 className="text-xl font-semibold">Master Template</h2>
    <dl className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{Object.entries(metadataLabels).map(([key, label]) => <div key={key}>
      <dt className="text-xs font-semibold">{label}</dt><dd className="mt-1 break-words whitespace-pre-wrap">{metadata[key] ?? 'Pendiente'}</dd>
    </div>)}</dl>
    <div className="no-print mt-5 flex flex-wrap gap-2">{editLinks.map((link) => <Link key={link.href} href={link.href} className="glass-button px-4">{link.label}</Link>)}</div>
  </section>;
}
export default function BudgetDocument({ preview }) {
  if (!preview) return <div className="glass-panel p-5">Documento aún no disponible. Completa y guarda la etapa correspondiente.</div>;
  return <div className="space-y-5">
    <MasterMetadata metadata={{ ...preview.metadata, invoice_code: preview.code ?? preview.metadata?.invoice_code }} />
    <div className="glass-panel p-3 financial-scroll"><table className="financial-table">
      <caption className="p-3 text-left">Detalle del documento {preview.code ?? 'pendiente de emisión'}</caption>
      <thead><tr><th scope="col">Descripción / unidad</th><th scope="col">Cantidad</th><th scope="col">Costo</th><th scope="col">Recibo</th><th scope="col">Total USD</th></tr></thead>
      <tbody>{preview.lines.map((line) => <tr key={line.id}>
        <th scope="row" className="text-left"><span lang="en">{line.description_en}</span><span className="block font-normal">{line.description_es}</span><span className="block text-xs">{line.unit}</span></th>
        <td className="money">{line.quantity ?? 'Por día'}</td><td className="money">{line.unit_cost}</td><td>{(line.receipts ?? [line.receipt_number]).filter(Boolean).join(', ') || '—'}</td><td className="money">{formatUsd(line.total)}</td>
      </tr>)}</tbody>
    </table></div>
    {preview.initial_lines && <details className="glass-panel p-4">
      <summary className="cursor-pointer py-2 font-semibold">Comparar con el detalle Initial emitido</summary>
      <div className="financial-scroll"><table className="financial-table">
        <caption className="p-3 text-left">Instantánea Initial vinculada, independiente de los gastos actuales</caption>
        <thead><tr><th scope="col">Descripción / unidad</th><th scope="col">Cantidad Initial</th><th scope="col">Costo Initial</th><th scope="col">Total Initial</th></tr></thead>
        <tbody>{preview.initial_lines.map((line) => <tr key={line.id}>
          <th scope="row" className="text-left">{line.description_en}<span className="block text-sm">{line.description_es} · {line.unit}</span></th>
          <td className="money">{line.quantity ?? 'Por día'}</td><td className="money">{line.unit_cost}</td><td className="money">{formatUsd(line.total)}</td>
        </tr>)}</tbody>
      </table></div>
    </details>}
    <FinancialSummary totals={preview.totals} />
    <section className="glass-panel p-5"><h2 className="font-semibold">Notas y métodos de pago</h2>
      {preview.legal_notes.map((note, index) => <p key={index} className="mt-3 whitespace-pre-wrap">{note}</p>)}
      <dl className="mt-4 space-y-3">{preview.payment_methods.map((method) => <div key={method.label}><dt className="font-semibold">{method.label}</dt><dd className="whitespace-pre-wrap">{method.description}</dd></div>)}</dl>
    </section>
    <div className="no-print flex flex-wrap gap-3">
      {preview.download_url && <a href={preview.download_url} className="glass-button px-5">Descargar PDF</a>}
      {preview.issue_url && <Link href={preview.issue_url} className="glass-button px-5">Revisar emisión</Link>}
    </div>
  </div>;
}
