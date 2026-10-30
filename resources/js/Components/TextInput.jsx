import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import LiquidDateInput from '@/Components/Glass/LiquidDateInput';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    if (type === 'date') return <LiquidDateInput {...props} className={`ios-field min-h-11 ${className}`} ref={localRef} />;

    return (
        <input
            {...props}
            type={type}
            className={
                'lg-micro-glass ios-field min-h-11 placeholder:text-app-muted/70 aria-[invalid=true]:border-red-700 dark:aria-[invalid=true]:border-red-300 ' +
                className
            }
            ref={localRef}
        />
    );
});
