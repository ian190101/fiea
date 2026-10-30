export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'h-5 w-5 rounded border-app-border text-brand-primary shadow-sm focus-visible:ring-2 focus-visible:ring-brand-primary ' +
                className
            }
        />
    );
}
