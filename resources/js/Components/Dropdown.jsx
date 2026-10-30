import { Fragment } from 'react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link } from '@inertiajs/react';

function Dropdown({ children }) {
    return <Menu as="div" className="relative">{children}</Menu>;
}
function Trigger({ children }) {
    return <MenuButton as={Fragment}>{children}</MenuButton>;
}
function Content({ align = 'right', width = '48', contentClasses = '', children }) {
    return <MenuItems transition
        anchor={align === 'left' ? 'bottom start' : 'bottom end'}
        className={`glass-panel z-[70] mt-2 max-h-80 overflow-auto p-1 shadow-xl focus:outline-none ${width === '48' ? 'w-48' : ''} ${contentClasses}`}>
        {children}
    </MenuItems>;
}
function DropdownLink({ className = '', children, ...props }) {
    return <MenuItem>
        {({ focus }) => <Link {...props}
            className={`block min-h-11 w-full rounded-xl px-4 py-3 text-start text-sm text-app-text focus-visible:outline focus-visible:outline-2 ${focus ? 'bg-brand-primary/15' : ''} ${className}`}>
            {children}
        </Link>}
    </MenuItem>;
}
Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;
export default Dropdown;