import { Head, Link } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Register() {
    return <GuestLayout>
        <Head title="Registro cerrado" />
        <h1 className="text-2xl font-semibold">Registro público cerrado</h1>
        <p className="mt-3">Un administrador crea las cuentas y asigna sus permisos.</p>
        <Link href={route('login')} className="glass-button mt-6 px-5">Iniciar sesión</Link>
    </GuestLayout>;
}