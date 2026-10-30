import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function ForgotPassword({ status }) {
    const formRef = useRef(null);
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.email'), {
            onError: () => requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus()),
        });
    };

    return (
        <GuestLayout>
            <Head title="Recuperar contraseña" />

            <div className="mb-4 text-sm text-gray-600">
                Si existe una cuenta asociada a este correo, enviaremos un enlace para cambiar la contraseña.
            </div>

            {status && (
                <div role="status" aria-live="polite" className="mb-4 text-sm font-medium text-green-700">
                    {status}
                </div>
            )}

            {Object.keys(errors).length > 0 && <p role="alert" className="mb-4 text-sm font-semibold text-red-700 dark:text-red-200">Revisa el correo indicado.</p>}
            <form ref={formRef} onSubmit={submit}>
                <InputLabel htmlFor="email" className="block text-sm font-semibold">Correo electrónico</InputLabel>
                <TextInput
                    id="email"
                        aria-invalid={Boolean(errors.email)}
                        aria-describedby={errors.email ? "email-error" : undefined}
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    isFocused={true}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <InputError id="email-error" message={errors.email} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton type="submit" className="ms-4" disabled={processing}>
                        Enviar enlace de recuperación
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
