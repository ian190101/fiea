import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function BudgetStart({tripPhase,invoice_type,canCreate,createUrl}) {
    const form=useForm({invoice_type,billing_mode:'standard',metadata:{document_date:''}});
    return <AuthenticatedLayout header={<h1 className="text-2xl font-bold">Iniciar matriz · {tripPhase.phase}</h1>}>
        <Head title="Iniciar matriz"/>
        <form className="glass-panel max-w-xl space-y-4 p-6" onSubmit={event=>{event.preventDefault();if(canCreate)form.post(createUrl);}}>
            <p>Este viaje todavía no tiene expediente {invoice_type}. Completa la cabecera para iniciar su matriz.</p>
            <label htmlFor="start-billing-mode" className="block">Modalidad</label><select id="start-billing-mode" className="ios-field w-full" value={form.data.billing_mode}
                onChange={event=>form.setData('billing_mode',event.target.value)} disabled={!canCreate}>
                <option value="standard">Initial y Final</option><option value="final_only">Solo Final</option>
            </select>
            <label className="block">Fecha documental<input type="date" required className="ios-field w-full" value={form.data.metadata.document_date}
                onChange={event=>form.setData('metadata',{document_date:event.target.value})} disabled={!canCreate}/></label>
            {Object.values(form.errors).map((error,index)=><p role="alert" key={index}>{error}</p>)}
            {canCreate?<button className="glass-button px-4" disabled={form.processing}>Crear expediente e iniciar matriz</button>:<p>No tienes permiso para iniciar este expediente.</p>}
        </form>
    </AuthenticatedLayout>;
}
