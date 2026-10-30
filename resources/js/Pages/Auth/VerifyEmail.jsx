import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verifica tu correo" />

            <div className="mb-4 text-sm text-gray-600">
                Revisa el enlace que enviamos a tu correo para verificar tu cuenta. Si no llegó, puedes solicitar otro.
            </div>

            {status === 'verification-link-sent' && (
                <div role="status" aria-live="polite" className="mb-4 text-sm font-medium text-green-700">
                    Enviamos un nuevo enlace de verificación a tu correo.
                </div>
            )}

            <form onSubmit={submit}>
                <div className="mt-4 flex items-center justify-between">
                    <PrimaryButton type="submit" disabled={processing}>
                        Reenviar verificación
                    </PrimaryButton>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Cerrar sesión
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
