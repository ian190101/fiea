import HelpTooltip from './HelpTooltip';
import { fieldHelp } from '@/Content/fieldHelp';

export default function InputLabel({
    value,
    className = '',
    children,
    help,
    ...props
}) {
    return (
        <span className="inline-flex items-center gap-1">
        <label
            {...props}
            className={
                `block text-sm font-semibold text-app-text ` +
                className
            }
        >
            {value ? value : children}
        </label>
        {help !== false && <HelpTooltip label={typeof (value ?? children) === 'string' ? (value ?? children) : 'este campo'} text={typeof help === 'string' ? help : fieldHelp(props.htmlFor, value ?? children)} />}
        </span>
    );
}
