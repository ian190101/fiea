export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center justify-center rounded-xl border border-transparent bg-brand-primary px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-950/10 transition duration-150 ease-in-out hover:-translate-y-0.5 hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 active:translate-y-0 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
