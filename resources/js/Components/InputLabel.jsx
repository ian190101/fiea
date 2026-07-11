export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}) {
    return (
        <label
            {...props}
            className={
                `block text-sm font-semibold text-app-text ` +
                className
            }
        >
            {value ? value : children}
        </label>
    );
}
