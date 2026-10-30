import { Description, Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
export default function FinancialActionSheet({ open, title, description, confirmLabel = 'Confirmar', busy = false, confirmDisabled = false, onCancel, onConfirm, children }) {
  return <Dialog open={open} onClose={() => { if (!busy) onCancel(); }} className="relative z-[80]">
    <div className="fixed inset-0 bg-slate-950/45" aria-hidden="true" />
    <div className="fixed inset-0 flex items-end justify-center overflow-y-auto p-3 sm:items-center">
      <DialogPanel className="glass-panel w-full max-w-lg p-6" aria-busy={busy}>
        <DialogTitle className="text-xl font-semibold">{title}</DialogTitle><Description className="mt-3 leading-relaxed">{description}</Description>
        {children}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button type="button" data-autofocus className="glass-button px-5" disabled={busy} onClick={onCancel}>Cancelar</button>
          <button type="button" className="glass-button px-5 font-semibold" disabled={busy || confirmDisabled} onClick={onConfirm}>{busy ? 'Procesando…' : confirmLabel}</button>
        </div>
      </DialogPanel>
    </div>
  </Dialog>;
}
