import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

export default function ContactIndex({ contacts = [], assignments = [], chapters = [], teams = [], roleOptions = [] }) {
    const { flash } = usePage().props;
    const [editingContact, setEditingContact] = useState(null);
    const [editingAssignment, setEditingAssignment] = useState(null);
    const [selectedContactId, setSelectedContactId] = useState(contacts[0]?.id ?? '');

    const selectedContact = useMemo(
        () => contacts.find((contact) => String(contact.id) === String(selectedContactId)) ?? null,
        [contacts, selectedContactId],
    );

    const selectedAssignments = useMemo(
        () => assignments.filter((assignment) => String(assignment.contact_person_id) === String(selectedContactId)),
        [assignments, selectedContactId],
    );
    const contactSearchText = useCallback((contact) => [
        contact.full_name,
        contact.email,
        contact.phone,
        contact.physical_address,
    ].filter(Boolean).join(' '), []);
    const assignmentSearchText = useCallback((assignment) => [
        assignment.role,
        assignment.chapter?.name,
        assignment.team?.name,
        assignment.is_billing_contact ? 'facturacion billing' : '',
        assignment.is_email_recipient ? 'correo email' : '',
        assignment.is_active ? 'activo' : 'inactivo',
    ].filter(Boolean).join(' '), []);
    const contactTable = useLocalTable(contacts, contactSearchText, 10);
    const assignmentTable = useLocalTable(selectedAssignments, assignmentSearchText, 10);

    const contactForm = useForm({
        full_name: '',
        email: '',
        phone: '',
        physical_address: '',
    });

    const assignmentForm = useForm({
        contact_person_id: selectedContactId,
        chapter_id: '',
        team_id: '',
        role: roleOptions[0] ?? 'Primary Contact',
        is_billing_contact: false,
        is_email_recipient: true,
        is_active: true,
    });

    const availableTeams = useMemo(() => {
        if (!assignmentForm.data.chapter_id) {
            return teams;
        }

        return teams.filter((team) => String(team.chapter_id) === String(assignmentForm.data.chapter_id));
    }, [teams, assignmentForm.data.chapter_id]);

    const submitContact = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetContactForm(),
        };

        if (editingContact) {
            contactForm.patch(route('contacts.update', editingContact.id), options);
            return;
        }

        contactForm.post(route('contacts.store'), options);
    };

    const submitAssignment = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetAssignmentForm(),
        };

        if (editingAssignment) {
            assignmentForm.patch(route('contact-assignments.update', editingAssignment.id), options);
            return;
        }

        assignmentForm.post(route('contact-assignments.store'), options);
    };

    const editContact = (contact) => {
        setEditingContact(contact);
        setSelectedContactId(contact.id);
        contactForm.clearErrors();
        contactForm.setData({
            full_name: contact.full_name ?? '',
            email: contact.email ?? '',
            phone: contact.phone ?? '',
            physical_address: contact.physical_address ?? '',
        });
    };

    const editAssignment = (assignment) => {
        setEditingAssignment(assignment);
        setSelectedContactId(assignment.contact_person_id);
        assignmentForm.clearErrors();
        assignmentForm.setData({
            contact_person_id: assignment.contact_person_id,
            chapter_id: assignment.chapter_id ?? '',
            team_id: assignment.team_id ?? '',
            role: assignment.role ?? roleOptions[0] ?? 'Primary Contact',
            is_billing_contact: Boolean(assignment.is_billing_contact),
            is_email_recipient: Boolean(assignment.is_email_recipient),
            is_active: Boolean(assignment.is_active),
        });
    };

    const resetContactForm = () => {
        setEditingContact(null);
        contactForm.clearErrors();
        contactForm.setData({
            full_name: '',
            email: '',
            phone: '',
            physical_address: '',
        });
    };

    const resetAssignmentForm = () => {
        setEditingAssignment(null);
        assignmentForm.clearErrors();
        assignmentForm.setData({
            contact_person_id: selectedContactId,
            chapter_id: '',
            team_id: '',
            role: roleOptions[0] ?? 'Primary Contact',
            is_billing_contact: false,
            is_email_recipient: true,
            is_active: true,
        });
    };

    const deleteContact = (contact) => {
        if (!window.confirm(`Eliminar "${contact.full_name}"?`)) {
            return;
        }

        router.delete(route('contacts.destroy', contact.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingContact?.id === contact.id) {
                    resetContactForm();
                }
            },
        });
    };

    const deleteAssignment = (assignment) => {
        if (!window.confirm(`Eliminar asignacion "${assignment.role}"?`)) {
            return;
        }

        router.delete(route('contact-assignments.destroy', assignment.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingAssignment?.id === assignment.id) {
                    resetAssignmentForm();
                }
            },
        });
    };

    const chooseContact = (contactId) => {
        setSelectedContactId(contactId);
        assignmentForm.setData('contact_person_id', contactId);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Contactos y correos
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Personas y asignaciones
                    </h1>
                </div>
            }
        >
            <Head title="Contactos" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <section className="grid gap-6 xl:grid-cols-[360px_1fr]">
                    <form onSubmit={submitContact} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingContact ? 'Editar contacto' : 'Nuevo contacto'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                La persona puede repetirse en varios equipos o capitulos mediante asignaciones.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <TextField
                                id="full_name"
                                label="Nombre completo"
                                value={contactForm.data.full_name}
                                error={contactForm.errors.full_name}
                                onChange={(value) => contactForm.setData('full_name', value)}
                            />
                            <TextField
                                id="email"
                                label="Correo"
                                type="email"
                                value={contactForm.data.email}
                                error={contactForm.errors.email}
                                onChange={(value) => contactForm.setData('email', value)}
                            />
                            <TextField
                                id="phone"
                                label="Telefono"
                                value={contactForm.data.phone}
                                error={contactForm.errors.phone}
                                onChange={(value) => contactForm.setData('phone', value)}
                            />
                            <TextareaField
                                id="physical_address"
                                label="Direccion fisica"
                                value={contactForm.data.physical_address}
                                error={contactForm.errors.physical_address}
                                onChange={(value) => contactForm.setData('physical_address', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={contactForm.processing}>
                                {editingContact ? 'Guardar cambios' : 'Crear contacto'}
                            </PrimaryButton>
                            {editingContact && (
                                <button type="button" className="glass-button" onClick={resetContactForm}>
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Contactos registrados</h2>
                            <p className="text-sm text-app-muted">{contactTable.filteredRows.length} de {contacts.length} personas</p>
                            <TableControls
                                query={contactTable.query}
                                onQueryChange={contactTable.updateQuery}
                                page={contactTable.page}
                                totalPages={contactTable.totalPages}
                                pageSize={contactTable.pageSize}
                                onPageSizeChange={contactTable.updatePageSize}
                                onPageChange={contactTable.setPage}
                                total={contacts.length}
                                filtered={contactTable.filteredRows.length}
                                placeholder="Buscar contacto"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Contacto</th>
                                        <th className="px-5 py-4">Correo</th>
                                        <th className="px-5 py-4">Asignaciones</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {contactTable.paginatedRows.map((contact) => (
                                        <tr key={contact.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-bold">{contact.full_name}</div>
                                                <div className="text-xs text-app-muted">{contact.phone || '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">{contact.email || '-'}</td>
                                            <td className="px-5 py-4">
                                                {contact.active_assignments_count} activas / {contact.assignments_count} total
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="file" label="Ver asignaciones" type="button" onClick={() => chooseContact(contact.id)} />
                                                    <IconButton icon="edit" label="Editar contacto" type="button" onClick={() => editContact(contact)} />
                                                    <IconButton icon="trash" label="Eliminar contacto" type="button" variant="danger" onClick={() => deleteContact(contact)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {contactTable.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="4" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay contactos.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[360px_1fr]">
                    <form onSubmit={submitAssignment} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingAssignment ? 'Editar asignacion' : 'Nueva asignacion'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Define rol, relacion y si esta persona recibe correos automaticos.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="contact_person_id"
                                label="Contacto"
                                value={assignmentForm.data.contact_person_id}
                                options={contacts.map((contact) => ({ id: contact.id, name: contact.full_name }))}
                                error={assignmentForm.errors.contact_person_id}
                                onChange={(value) => {
                                    setSelectedContactId(value);
                                    assignmentForm.setData('contact_person_id', value);
                                }}
                            />
                            <SelectField
                                id="chapter_id"
                                label="Capitulo"
                                value={assignmentForm.data.chapter_id}
                                options={chapters}
                                error={assignmentForm.errors.chapter_id}
                                emptyLabel="Sin capitulo directo"
                                onChange={(value) => {
                                    assignmentForm.setData('chapter_id', value);
                                    if (value && !teams.some((team) => String(team.id) === String(assignmentForm.data.team_id) && String(team.chapter_id) === String(value))) {
                                        assignmentForm.setData('team_id', '');
                                    }
                                }}
                            />
                            <SelectField
                                id="team_id"
                                label="Equipo"
                                value={assignmentForm.data.team_id}
                                options={availableTeams}
                                error={assignmentForm.errors.team_id}
                                emptyLabel="Sin equipo directo"
                                onChange={(value) => assignmentForm.setData('team_id', value)}
                            />
                            <SelectField
                                id="role"
                                label="Rol"
                                value={assignmentForm.data.role}
                                options={roleOptions.map((role) => ({ id: role, name: role }))}
                                error={assignmentForm.errors.role}
                                onChange={(value) => assignmentForm.setData('role', value)}
                            />
                            <ToggleField
                                label="Contacto de facturacion"
                                checked={assignmentForm.data.is_billing_contact}
                                onChange={(value) => assignmentForm.setData('is_billing_contact', value)}
                            />
                            <ToggleField
                                label="Recibe correos automaticos"
                                checked={assignmentForm.data.is_email_recipient}
                                onChange={(value) => assignmentForm.setData('is_email_recipient', value)}
                            />
                            <ToggleField
                                label="Asignacion activa"
                                checked={assignmentForm.data.is_active}
                                onChange={(value) => assignmentForm.setData('is_active', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={assignmentForm.processing || contacts.length === 0}>
                                {editingAssignment ? 'Guardar cambios' : 'Crear asignacion'}
                            </PrimaryButton>
                            {editingAssignment && (
                                <button type="button" className="glass-button" onClick={resetAssignmentForm}>
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                                <div>
                                    <h2 className="text-xl font-black">Asignaciones</h2>
                                    <p className="text-sm text-app-muted">
                                        {selectedContact ? selectedContact.full_name : 'Sin contacto seleccionado'}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-white/30 px-4 py-2 text-sm font-black text-app-text dark:bg-white/10">
                                    {countEmailRecipients(selectedAssignments)} destinatarios activos
                                </div>
                            </div>
                            <TableControls
                                query={assignmentTable.query}
                                onQueryChange={assignmentTable.updateQuery}
                                page={assignmentTable.page}
                                totalPages={assignmentTable.totalPages}
                                pageSize={assignmentTable.pageSize}
                                onPageSizeChange={assignmentTable.updatePageSize}
                                onPageChange={assignmentTable.setPage}
                                total={selectedAssignments.length}
                                filtered={assignmentTable.filteredRows.length}
                                placeholder="Buscar asignacion"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Rol</th>
                                        <th className="px-5 py-4">Relacion</th>
                                        <th className="px-5 py-4">Flags</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {assignmentTable.paginatedRows.map((assignment) => (
                                        <tr key={assignment.id} className="text-sm">
                                            <td className="px-5 py-4 font-bold">{assignment.role}</td>
                                            <td className="px-5 py-4">
                                                <div>{assignment.chapter?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{assignment.team?.name ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex flex-wrap gap-2">
                                                    <StatusPill enabled={assignment.is_billing_contact} label="Billing" />
                                                    <StatusPill enabled={assignment.is_email_recipient} label="Email" />
                                                    <StatusPill enabled={assignment.is_active} label="Activo" />
                                                </div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar asignacion" type="button" onClick={() => editAssignment(assignment)} />
                                                    <IconButton icon="trash" label="Eliminar asignacion" type="button" variant="danger" onClick={() => deleteAssignment(assignment)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {assignmentTable.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="4" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay asignaciones para este contacto.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function FlashMessages({ flash }) {
    const contactError = flash.errors?.contact?.[0];
    const assignmentError = flash.errors?.assignment?.[0];

    if (!flash.success && !contactError && !assignmentError) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {contactError && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {contactError}
                </div>
            )}
            {assignmentError && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {assignmentError}
                </div>
            )}
        </div>
    );
}

function SelectField({ id, label, value, options, error, onChange, emptyLabel = 'Seleccionar' }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                <option value="">{emptyLabel}</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.name}
                    </option>
                ))}
            </select>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function TextField({ id, label, value, error, onChange, type = 'text' }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput
                id={id}
                type={type}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function TextareaField({ id, label, value, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <textarea
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block min-h-24 w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm placeholder:text-app-muted/70 focus:border-brand-primary focus:ring-brand-primary dark:bg-white/10"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function ToggleField({ label, checked, onChange }) {
    return (
        <label className="flex items-center justify-between rounded-2xl border border-white/35 bg-white/25 px-4 py-3 text-sm font-bold text-app-text dark:border-white/10 dark:bg-white/10">
            <span>{label}</span>
            <input
                type="checkbox"
                checked={Boolean(checked)}
                onChange={(event) => onChange(event.target.checked)}
                className="rounded border-app-border text-brand-primary focus:ring-brand-primary"
            />
        </label>
    );
}

function StatusPill({ enabled, label }) {
    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black ${enabled ? 'bg-brand-primary text-white' : 'bg-white/30 text-app-muted dark:bg-white/10'}`}>
            {label}
        </span>
    );
}

function countEmailRecipients(assignments) {
    return assignments.filter((assignment) => assignment.is_active && assignment.is_email_recipient).length;
}
