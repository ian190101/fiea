import { Description, Dialog, DialogPanel, DialogTitle } from '@headlessui/react';

export default function Modal({
    children, show = false, maxWidth = '2xl', closeable = true,
    onClose = () => {}, title, description,
}) {
    const widths = {
        sm: 'sm:max-w-sm', md: 'sm:max-w-md', lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl', '2xl': 'sm:max-w-2xl',
    };
    return <Dialog open={show} onClose={() => { if (closeable) onClose(); }} className="relative z-[80]">
        <div className="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
        <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4">
            <DialogPanel className={`glass-panel w-full p-6 ${widths[maxWidth] ?? widths['2xl']}`}>
                <DialogTitle className="text-xl font-semibold">{title}</DialogTitle>
                {description && <Description className="mt-2 text-sm">{description}</Description>}
                <div className="mt-4">{children}</div>
            </DialogPanel>
        </div>
    </Dialog>;
}