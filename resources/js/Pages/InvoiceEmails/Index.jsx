import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const recipientTypes = [
    { id: 'to', name: 'TO' },
    { id: 'cc', name: 'CC' },
    { id: 'bcc', name: 'BCC' },
];

export default function InvoiceEmailIndex({ invoices = [], defaultMessages = {}, templates = [], templateTokens = [], contacts = [], recommendedRecipients = {}, emailLogs = { data: [] }, automationSummary = {} }) {
    const { flash } = usePage().props;
    const [selectedInvoiceId, setSelectedInvoiceId] = useState(invoices[0]?.id ?? '');
    const [manualEmail, setManualEmail] = useState('');
    const [manualType, setManualType] = useState('to');
    const [editingTemplate, setEditingTemplate] = useState(null);

    const selectedInvoice = useMemo(
        () => invoices.find((invoice) => String(invoice.id) === String(selectedInvoiceId)),
        [invoices, selectedInvoiceId],
    );
    const selectedMessage = selectedInvoice ? defaultMessages[selectedInvoice.id] : null;

    const form = useForm({
        subject: selectedMessage?.subject ?? '',
        body: selectedMessage?.body ?? '',
        recipients: selectedInvoice ? initialRecipients(selectedInvoice, recommendedRecipients) : [],
    });
    const templateForm = useForm({
        name: '',
        invoice_type: 'IC',
        stage: 'initial',
        subject_template: 'FIEA {{invoice_type}} {{stage}} Invoice - {{project_code}}',
        body_template: defaultTemplateBody(),
        is_active: true,
    });

    const changeInvoice = (invoiceId) => {
        const invoice = invoices.find((item) => String(item.id) === String(invoiceId));
        setSelectedInvoiceId(invoiceId);
        form.clearErrors();
        form.setData({
            subject: invoice ? defaultMessages[invoice.id]?.subject ?? '' : '',
            body: invoice ? defaultMessages[invoice.id]?.body ?? '' : '',
            recipients: invoice ? initialRecipients(invoice, recommendedRecipients) : [],
        });
    };

    const startTemplateEdit = (template) => {
        setEditingTemplate(template);
        templateForm.clearErrors();
        templateForm.setData({
            name: template.name ?? '',
            invoice_type: template.invoice_type ?? 'IC',
            stage: template.stage ?? 'initial',
            subject_template: template.subject_template ?? '',
            body_template: template.body_template ?? '',
            is_active: Boolean(template.is_active),
        });
    };

    const resetTemplateForm = () => {
        setEditingTemplate(null);
        templateForm.clearErrors();
        templateForm.setData({
            name: '',
            invoice_type: 'IC',
            stage: 'initial',
            subject_template: 'FIEA {{invoice_type}} {{stage}} Invoice - {{project_code}}',
            body_template: defaultTemplateBody(),
            is_active: true,
        });
    };

    const addContact = (contact, recipientType = 'to') => {
        addRecipient({
            contact_person_id: contact.id,
            email: contact.email,
            recipient_type: recipientType,
            label: contact.full_name,
        });
    };

    const addManualRecipient = () => {
        if (!manualEmail.trim()) {
            return;
        }

        addRecipient({
            contact_person_id: null,
            email: manualEmail.trim(),
            recipient_type: manualType,
            label: manualEmail.trim(),
        });
        setManualEmail('');
    };

    const addRecipient = (recipient) => {
        const exists = form.data.recipients.some((item) => (
            item.email.toLowerCase() === recipient.email.toLowerCase()
            && item.recipient_type === recipient.recipient_type
        ));

        if (exists) {
            return;
        }

        form.setData('recipients', [...form.data.recipients, recipient]);
    };

    const removeRecipient = (index) => {
        form.setData('recipients', form.data.recipients.filter((_, itemIndex) => itemIndex !== index));
    };

    const submit = (event) => {
        event.preventDefault();

        if (!selectedInvoice) {
            return;
        }

        form.post(route('invoice-emails.store', selectedInvoice.id), {
            preserveScroll: true,
        });
    };

    const sendEmail = (log) => {
        router.post(route('invoice-emails.send', log.id), {}, { preserveScroll: true });
    };

    const submitTemplate = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: resetTemplateForm,
        };

        if (editingTemplate) {
            templateForm.patch(route('email-templates.update', editingTemplate.id), options);
            return;
        }

        templateForm.post(route('email-templates.store'), options);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Correos</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Preparacion de emails</h1>
                </div>
            }
        >
            <Head title="Correos de invoices" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />
                <AutomationSummary summary={automationSummary} />

                <section className="grid gap-6 xl:grid-cols-[430px_1fr]">
                    <form onSubmit={submit} className="glass-panel h-fit rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">Correo de invoice</h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                El asunto y cuerpo quedan en ingles para el envio a Estados Unidos.
                            </p>
                            <p className="mt-2 text-xs font-bold text-app-muted">
                                Al enviar, el sistema adjunta el PDF del invoice y lo genera automaticamente si falta.
                            </p>
                        </div>

                        <div className="mt-5 space-y-4">
                            <SelectField
                                id="invoice_id"
                                label="Invoice"
                                value={selectedInvoiceId}
                                options={invoices.map((invoice) => ({ id: invoice.id, name: invoiceLabel(invoice) }))}
                                emptyLabel="Seleccionar invoice"
                                onChange={changeInvoice}
                            />

                            <TextField
                                id="subject"
                                label="Subject"
                                value={form.data.subject}
                                error={form.errors.subject}
                                onChange={(value) => form.setData('subject', value)}
                            />

                            <div>
                                <InputLabel htmlFor="body" value="Body" />
                                <textarea
                                    id="body"
                                    rows="7"
                                    value={form.data.body}
                                    onChange={(event) => form.setData('body', event.target.value)}
                                    className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                                />
                                <InputError message={form.errors.body} className="mt-2" />
                            </div>

                            <div className="rounded-2xl bg-white/30 p-4 dark:bg-white/10">
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-black uppercase text-app-muted">Destinatarios</h3>
                                    <span className="text-xs font-bold text-app-muted">{form.data.recipients.length} seleccionados</span>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {form.data.recipients.map((recipient, index) => (
                                        <div key={`${recipient.email}-${recipient.recipient_type}-${index}`} className="flex items-center justify-between gap-3 rounded-2xl bg-white/40 px-3 py-2 text-sm dark:bg-white/10">
                                            <div className="min-w-0">
                                                <div className="truncate font-black">{recipient.email}</div>
                                                <div className="text-xs font-bold uppercase text-app-muted">{recipient.recipient_type}</div>
                                            </div>
                                            <button type="button" className="glass-button px-3 py-1.5 text-xs" onClick={() => removeRecipient(index)}>
                                                Quitar
                                            </button>
                                        </div>
                                    ))}
                                    {form.data.recipients.length === 0 && (
                                        <p className="text-sm font-semibold text-app-muted">Agrega al menos un TO.</p>
                                    )}
                                </div>
                                <InputError message={form.errors.recipients} className="mt-2" />
                            </div>

                            <div className="grid gap-3 sm:grid-cols-[1fr_90px]">
                                <TextInput
                                    value={manualEmail}
                                    onChange={(event) => setManualEmail(event.target.value)}
                                    placeholder="manual@example.com"
                                    className="block w-full"
                                />
                                <select
                                    value={manualType}
                                    onChange={(event) => setManualType(event.target.value)}
                                    className="rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                                >
                                    {recipientTypes.map((type) => (
                                        <option key={type.id} value={type.id}>{type.name}</option>
                                    ))}
                                </select>
                            </div>
                            <button type="button" className="glass-button w-full px-4 py-2" onClick={addManualRecipient}>
                                Agregar email manual
                            </button>
                        </div>

                        <div className="mt-6">
                            <PrimaryButton disabled={form.processing || !selectedInvoice}>
                                Preparar correo
                            </PrimaryButton>
                        </div>
                    </form>

                    <div className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">Contactos disponibles</h2>
                            <div className="mt-4 grid gap-3 md:grid-cols-2">
                                {contacts.map((contact) => (
                                    <div key={contact.id} className="rounded-2xl bg-white/30 p-3 dark:bg-white/10">
                                        <div className="font-black">{contact.full_name}</div>
                                        <div className="truncate text-sm text-app-muted">{contact.email}</div>
                                        <div className="mt-3 flex gap-2">
                                            {recipientTypes.map((type) => (
                                                <button key={type.id} type="button" className="glass-button px-3 py-1.5 text-xs" onClick={() => addContact(contact, type.id)}>
                                                    {type.name}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                                {contacts.length === 0 && (
                                    <p className="text-sm font-semibold text-app-muted">No hay contactos con correo registrado.</p>
                                )}
                            </div>
                        </div>

                        <TemplatePanel
                            templates={templates}
                            templateTokens={templateTokens}
                            form={templateForm}
                            editingTemplate={editingTemplate}
                            onEdit={startTemplateEdit}
                            onCancel={resetTemplateForm}
                            onSubmit={submitTemplate}
                        />

                        <div className="glass-panel overflow-hidden rounded-[2rem]">
                            <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                                <h2 className="text-xl font-black">Historial de correos</h2>
                                <p className="text-sm text-app-muted">Registros preparados, enviados o fallidos por invoice.</p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                    <thead>
                                        <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                            <th className="px-5 py-4">Invoice</th>
                                            <th className="px-5 py-4">Subject</th>
                                            <th className="px-5 py-4">Origen</th>
                                            <th className="px-5 py-4">Estado</th>
                                            <th className="px-5 py-4">Fecha</th>
                                            <th className="px-5 py-4 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                        {(emailLogs.data ?? []).map((log) => (
                                            <tr key={log.id} className="text-sm">
                                                <td className="px-5 py-4 font-black">{log.invoice?.code ?? '-'}</td>
                                                <td className="px-5 py-4">{log.subject}</td>
                                                <td className="px-5 py-4">
                                                    <div className="font-bold">{log.source === 'automation' ? 'Automatico' : 'Manual'}</div>
                                                    {Number(log.retry_count ?? 0) > 0 && (
                                                        <div className="text-xs font-semibold text-app-muted">{log.retry_count} reintentos</div>
                                                    )}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <EmailStatusBadge status={log.status} />
                                                    {log.error_message && (
                                                        <div className="mt-1 max-w-xs truncate text-xs font-semibold text-red-600 dark:text-red-300">
                                                            {log.error_message}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-5 py-4">{formatDate(log.sent_at ?? log.created_at)}</td>
                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end">
                                                        {['pending', 'failed'].includes(log.status) && (
                                                            <IconButton
                                                                icon="check"
                                                                label={log.status === 'failed' ? 'Reintentar envio' : 'Enviar correo'}
                                                                type="button"
                                                                onClick={() => sendEmail(log)}
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {(emailLogs.data ?? []).length === 0 && (
                                            <tr>
                                                <td colSpan="6" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                    Aun no hay correos preparados.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <div className="flex justify-end gap-2 border-t border-white/30 px-5 py-4 dark:border-white/10">
                                {emailLogs.prev_page_url && <Link href={emailLogs.prev_page_url} className="glass-button px-3 py-2 text-xs">Anterior</Link>}
                                {emailLogs.next_page_url && <Link href={emailLogs.next_page_url} className="glass-button px-3 py-2 text-xs">Siguiente</Link>}
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function AutomationSummary({ summary }) {
    const cards = [
        { label: 'Automaticos pendientes', value: summary?.automated_pending ?? 0 },
        { label: 'Correos en cola', value: summary?.queued ?? 0 },
        { label: 'Fallidos listos para reintento', value: summary?.failed_due ?? 0 },
        { label: 'Reintentos agotados', value: summary?.max_retry_exceeded ?? 0 },
    ];

    return (
        <section className="grid gap-4 md:grid-cols-4">
            {cards.map((card) => (
                <div key={card.label} className="glass-panel rounded-[2rem] p-5">
                    <p className="text-sm font-bold text-app-muted">{card.label}</p>
                    <p className="mt-2 text-3xl font-black text-app-text">{card.value}</p>
                </div>
            ))}
        </section>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.email?.[0]
        ?? flash.errors?.recipients?.[0]
        ?? flash.errors?.subject?.[0]
        ?? flash.errors?.body?.[0]
        ?? flash.errors?.name?.[0]
        ?? flash.errors?.subject_template?.[0]
        ?? flash.errors?.body_template?.[0]
        ?? flash.errors?.stage?.[0];

    if (!flash.success && !error) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {error && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {error}
                </div>
            )}
        </div>
    );
}

function TemplatePanel({ templates, templateTokens, form, editingTemplate, onEdit, onCancel, onSubmit }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div>
                <h2 className="text-xl font-black">Plantillas de correo</h2>
                <p className="mt-2 text-sm leading-6 text-app-muted">
                    Estas plantillas generan el asunto y cuerpo sugeridos para cada invoice. El contenido debe mantenerse en ingles.
                </p>
            </div>

            <div className="mt-5 grid gap-3">
                {templates.map((template) => (
                    <div key={template.id} className="rounded-2xl bg-white/30 p-3 dark:bg-white/10">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate font-black">{template.name}</p>
                                <p className="text-xs font-bold uppercase text-app-muted">
                                    {template.invoice_type} / {stageLabel(template.stage)}
                                </p>
                            </div>
                            <button type="button" className="glass-button px-3 py-1.5 text-xs" onClick={() => onEdit(template)}>
                                Editar
                            </button>
                        </div>
                        <p className="mt-2 truncate text-xs font-semibold text-app-muted">{template.subject_template}</p>
                        {!template.is_active && (
                            <span className="mt-2 inline-flex rounded-full bg-amber-400/20 px-3 py-1 text-xs font-black text-amber-800 dark:text-amber-100">
                                Inactiva
                            </span>
                        )}
                    </div>
                ))}
            </div>

            <form onSubmit={onSubmit} className="mt-6 space-y-4">
                <div className="flex items-center justify-between gap-4">
                    <h3 className="text-sm font-black uppercase text-app-muted">
                        {editingTemplate ? 'Editar plantilla' : 'Nueva plantilla'}
                    </h3>
                    {editingTemplate && (
                        <button type="button" className="glass-button px-3 py-1.5 text-xs" onClick={onCancel}>
                            Cancelar
                        </button>
                    )}
                </div>

                <TextField
                    id="template_name"
                    label="Nombre"
                    value={form.data.name}
                    error={form.errors.name}
                    onChange={(value) => form.setData('name', value)}
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="template_invoice_type"
                        label="Tipo"
                        value={form.data.invoice_type}
                        options={[
                            { id: 'IC', name: 'IC' },
                            { id: 'MAT', name: 'MAT' },
                        ]}
                        onChange={(value) => form.setData('invoice_type', value)}
                    />
                    <SelectField
                        id="template_stage"
                        label="Etapa"
                        value={form.data.stage}
                        options={[
                            { id: 'initial', name: 'Initial' },
                            { id: 'final', name: 'Final' },
                        ]}
                        onChange={(value) => form.setData('stage', value)}
                    />
                </div>
                <InputError message={form.errors.stage} className="mt-2" />

                <TextField
                    id="subject_template"
                    label="Subject template"
                    value={form.data.subject_template}
                    error={form.errors.subject_template}
                    onChange={(value) => form.setData('subject_template', value)}
                />

                <div>
                    <InputLabel htmlFor="body_template" value="Body template" />
                    <textarea
                        id="body_template"
                        rows="8"
                        value={form.data.body_template}
                        onChange={(event) => form.setData('body_template', event.target.value)}
                        className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                    />
                    <InputError message={form.errors.body_template} className="mt-2" />
                </div>

                <label className="flex items-center justify-between gap-4 rounded-2xl bg-white/30 p-4 dark:bg-white/10">
                    <span>
                        <span className="block font-black">Activa</span>
                        <span className="text-sm text-app-muted">Si esta apagada, se usa una plantilla fallback.</span>
                    </span>
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        className="h-5 w-5 rounded border-app-border text-brand-primary focus:ring-brand-primary"
                    />
                </label>

                <div className="rounded-2xl bg-white/30 p-4 text-xs font-bold text-app-muted dark:bg-white/10">
                    <p className="mb-2 uppercase">Tokens disponibles</p>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(templateTokens).map(([token, label]) => (
                            <span key={token} title={label} className="rounded-full bg-white/40 px-3 py-1 dark:bg-white/10">
                                {token}
                            </span>
                        ))}
                    </div>
                </div>

                <PrimaryButton disabled={form.processing}>
                    {editingTemplate ? 'Actualizar plantilla' : 'Crear plantilla'}
                </PrimaryButton>
            </form>
        </div>
    );
}

function EmailStatusBadge({ status }) {
    const classes = {
        pending: 'bg-amber-400/20 text-amber-800 dark:text-amber-100',
        queued: 'bg-sky-400/20 text-sky-800 dark:text-sky-100',
        sent: 'bg-emerald-400/20 text-emerald-800 dark:text-emerald-100',
        failed: 'bg-red-400/20 text-red-800 dark:text-red-100',
    };
    const labels = {
        pending: 'Pendiente',
        queued: 'En cola',
        sent: 'Enviado',
        failed: 'Fallido',
    };

    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black uppercase ${classes[status] ?? classes.pending}`}>
            {labels[status] ?? status}
        </span>
    );
}

function SelectField({ id, label, value, options, emptyLabel, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                {emptyLabel && <option value="">{emptyLabel}</option>}
                {options.map((option) => (
                    <option key={option.id} value={option.id}>{option.name}</option>
                ))}
            </select>
        </div>
    );
}

function TextField({ id, label, value, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function initialRecipients(invoice, recommendedRecipients) {
    const existingRecipients = invoice.recipients ?? [];

    if (existingRecipients.length > 0) {
        return existingRecipients.map((recipient) => ({
            contact_person_id: recipient.contact_person_id,
            email: recipient.email,
            recipient_type: recipient.recipient_type,
            label: recipient.email,
        }));
    }

    return (recommendedRecipients[invoice.id] ?? []).map((recipient) => ({
        contact_person_id: recipient.id,
        email: recipient.email,
        recipient_type: recipient.recipient_type,
        label: recipient.full_name,
    }));
}

function defaultSubject(invoice) {
    return `FIEA ${invoice.type} ${stageLabel(invoice.stage)} Invoice - ${invoice.trip_phase?.project?.code ?? invoice.code}`;
}

function defaultBody(invoice) {
    return [
        'Hello,',
        '',
        `Please find the ${invoice.type} ${stageLabel(invoice.stage)} invoice for ${invoice.trip_phase?.project?.code ?? invoice.code}.`,
        '',
        `Invoice code: ${invoice.code}`,
        `Grand total: ${formatMoney(invoice.grand_total)}`,
        '',
        'Best regards,',
        'FIEA Team',
    ].join('\n');
}

function defaultTemplateBody() {
    return [
        'Hello,',
        '',
        'Please find the {{invoice_type}} {{stage}} invoice for {{project_code}} - {{project_name}} attached.',
        '',
        'Invoice code: {{invoice_code}}',
        'Grand total: {{grand_total}}',
        '',
        'Best regards,',
        'FIEA Team',
    ].join('\n');
}

function invoiceLabel(invoice) {
    return `${invoice.code} - ${invoice.type}/${stageLabel(invoice.stage)} - ${formatMoney(invoice.grand_total)}`;
}

function stageLabel(stage) {
    return stage === 'final' ? 'Final' : 'Initial';
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
