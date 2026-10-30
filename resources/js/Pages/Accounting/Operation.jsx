import InputLabel from '@/Components/InputLabel';
import LiquidSelect from '@/Components/Glass/LiquidSelect';
import LiquidDateInput from '@/Components/Glass/LiquidDateInput';
import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import { useUnsavedChangesGuard } from '@/Utils/useUnsavedChangesGuard';

export default function FinancialOperation({ title, description, fields, initialData, action, return_url }) {
  const form = useForm(initialData);
  const validMethod = ['post', 'patch'].includes(action?.method) && Boolean(action?.url);
  const guard = useUnsavedChangesGuard(form.isDirty);
  const [confirming, setConfirming] = useState(false);
  const [sending, setSending] = useState(false);
  const send = () => {
    if (sending || form.processing) return;
    if (!validMethod) return;
    setSending(true);
    form.submit(action.method, action.url, {
      preserveScroll: true,
      onSuccess: (page) => {
        if (page.props.initialData) { form.setData(page.props.initialData); form.setDefaults(page.props.initialData); }
      },
      onError: () => requestAnimationFrame(() => document.getElementById('operation-errors')?.focus()),
      onFinish: () => { setSending(false); setConfirming(false); },
    });
  };
  const submit = (event) => {
    event.preventDefault();
    if (action.requires_confirmation) setConfirming(true); else send();
  };
  const confirmationText = fields.map((field) => {
    const value = form.data[field.name] ?? 'Sin dato';
    const label = field.options?.find((option) => option.value === value)?.label ?? value;
    return `${field.label}: ${label}`;
  }).join(' · ');
  return <AuthenticatedLayout header={<h1 className="text-3xl font-semibold">{title}</h1>}>
    <Head title={title} />
    <form onSubmit={submit} className="glass-panel mx-auto max-w-3xl space-y-5 p-6" aria-busy={form.processing}>
      <p>{description}</p>
      {Object.keys(form.errors).length > 0 && <div id="operation-errors" role="alert" tabIndex={-1} className="rounded-xl border border-red-600 p-4">
        <p>La operación no se completó. Revisa estos campos:</p>
        <ul className="list-inside list-disc">{Object.entries(form.errors).map(([key, message]) => <li key={key}>{message}</li>)}</ul>
      </div>}
      {!validMethod && <p role="alert" className="rounded-xl border border-red-600 p-3">La operación no tiene un destino autorizado válido.</p>}
      {form.errors.version && <Link href={return_url} className="glass-button px-4">Revisar estado actual</Link>}
      {fields.map((field) => {
        const id = `operation-${field.name}`, error = form.errors[field.name];
        const common = {
          id, name: field.name, value: form.data[field.name] ?? '', required: field.required,
          disabled: form.processing, className: 'ios-field mt-2 w-full',
          'aria-invalid': Boolean(error), 'aria-describedby': `${id}-hint${error ? ` ${id}-error` : ''}`,
          onChange: (event) => form.setData(field.name, event.target.value),
        };
        return <div key={field.name}>
          <InputLabel htmlFor={id} className="font-semibold">{field.label}</InputLabel>
          {field.type === 'select' ? <LiquidSelect {...common}>
            <option value="">Selecciona una opción</option>{field.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </LiquidSelect> : field.type === 'textarea' ? <textarea {...common} rows={4} maxLength={2000} />
            : field.type === 'date' ? <LiquidDateInput {...common} /> : <input {...common} type="text"
              inputMode={field.type === 'decimal' ? 'decimal' : undefined} maxLength={field.type === 'decimal' ? 24 : 255} />}
          <p id={`${id}-hint`} className="mt-1 text-sm">{field.hint ?? ''}</p>
          {error && <p id={`${id}-error`} className="mt-1 text-sm text-red-700 dark:text-red-200">{error}</p>}
        </div>;
      })}
      <div className="flex flex-wrap gap-3">
        <button type="submit" className="glass-button px-5 font-semibold" disabled={form.processing || sending || !validMethod || Boolean(form.errors.version)}>{form.processing ? 'Guardando…' : action.label}</button>
        <Link href={return_url} className="glass-button px-5">Volver al control</Link>
      </div>
    </form>
    <FinancialActionSheet open={confirming} title="Revisar operación financiera" busy={form.processing || sending}
      description={`${title}. ${confirmationText}. Un cambio de estado no registra por sí mismo un pago.`}
      confirmLabel={action.label} onCancel={() => setConfirming(false)} onConfirm={send} />
    <FinancialActionSheet {...guard.dialogProps} />
  </AuthenticatedLayout>;
}
