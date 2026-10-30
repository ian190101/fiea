const styles = {
  ABIERTO: 'bg-sky-100 text-sky-950 dark:bg-sky-950 dark:text-sky-100',
  'POR GASTAR': 'bg-violet-100 text-violet-950 dark:bg-violet-950 dark:text-violet-100',
  'FINAL PEND': 'bg-amber-100 text-amber-950 dark:bg-amber-950 dark:text-amber-100',
  CERRADO: 'bg-emerald-100 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-100',
  ANULADO: 'bg-rose-100 text-rose-950 dark:bg-rose-950 dark:text-rose-100',
};
export default function FinancialStatusBadge({ status }) {
  return <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold ${styles[status] ?? 'bg-slate-100 text-slate-950'}`}>{status ?? 'Sin estado'}</span>;
}
