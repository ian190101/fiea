export default function EmptyState({ title, description, action }) {
  return <div className="glass-tile flex flex-col items-center gap-3 p-8 text-center">
    <svg aria-hidden="true" viewBox="0 0 64 64" className="h-16 w-16 text-app-muted" fill="none" stroke="currentColor" strokeWidth="2"><rect x="14" y="8" width="36" height="48" rx="8" /><path d="M23 22h18M23 31h18M23 40h10" /></svg>
    <h3 className="font-semibold">{title}</h3><p className="max-w-md text-sm">{description}</p>{action}
  </div>;
}
