import HelpTooltip from '@/Components/HelpTooltip';
import { financialHelp } from '@/Content/financialHelp';
import { formatUsd } from '@/Utils/money';
const fields = [
  ['total_dr', 'EIA SERVICES DR', 'initialDr'], ['total_wodr', 'EIA SERVICES WODR', 'initialWodr'],
  ['contingency', 'Contingency 10%', 'contingency'], ['arranged_fee', 'Arranged Services Fee 5%', 'arrangedFee'],
  ['material_fee', 'Material Fee 15%', 'materialFee'], ['bank_fee', 'Bank Fees', null],
  ['display_rounding_adjustment', 'Ajuste de presentación por redondeo', null],
  ['grand_total', 'TOTAL', null], ['balance_due', 'Balance Due documental', 'balanceDue'], ['credit', 'Crédito disponible', 'credit'],
];
export default function FinancialSummary({ totals, dirty = false }) {
  return <section className="glass-panel p-5"><h2 className="text-lg font-semibold">Desglose financiero</h2>
    {dirty && <p className="mt-2">Último cálculo guardado. Guarda los cambios antes de emitir.</p>}
    <dl className="mt-3 space-y-2">{fields.filter(([key]) => Object.hasOwn(totals, key)).map(([key, label, help]) => <div key={key} className="flex flex-wrap items-center justify-between gap-3">
      <dt>{label}{help && <HelpTooltip {...financialHelp[help]} />}</dt><dd className="money font-semibold">{formatUsd(totals[key])}</dd>
    </div>)}</dl>
  </section>;
}
