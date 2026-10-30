import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function ConfirmPassword() {
    const formRef = useRef(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
            onError: () => requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus()),
        });
    };

    return (
        <GuestLayout>
            <Head title="Confirmar contraseña" />

            <div className="mb-4 text-sm text-gray-600">
                Esta área contiene operaciones sensibles. Confirma tu contraseña para continuar.
            </div>

            {Object.keys(errors).length > 0 && <p role="alert" className="mb-4 text-sm font-semibold text-red-700 dark:text-red-200">Confirma la contraseña para continuar.</p>}
            <form ref={formRef} onSubmit={submit}>
                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Contraseña" />

                    <TextInput
                        id="password"
                        aria-invalid={Boolean(errors.password)}
                        aria-describedby={errors.password ? "password-error" : undefined}
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={data.password}
                        className="mt-1 block w-full"
                        isFocused={true}
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError id="password-error" message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton type="submit" className="ms-4" disabled={processing}>
                        Confirmar
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
