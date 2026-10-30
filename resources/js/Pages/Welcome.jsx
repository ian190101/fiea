import { Head, Link, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
export default function Welcome() {
  const user = usePage().props.auth?.user;
  return <GuestLayout><Head title="FIEA" /><h1 className="text-2xl font-semibold">Fundación Ingenieros en Acción</h1>
    <p className="mt-3">Presupuestos, facturación y conciliación de proyectos.</p>
    <Link href={route(user ? 'dashboard' : 'login')} className="glass-button mt-6 px-5">{user ? 'Abrir panel' : 'Iniciar sesión'}</Link>
  </GuestLayout>;
}
