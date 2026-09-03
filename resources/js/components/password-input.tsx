import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, Ref } from 'react';
import { cn } from '@/lib/utils';

type Props = Omit<ComponentProps<'input'>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
};

export default function PasswordInput({ className, ref, ...props }: Props) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="app-password">
            <input
                {...props}
                ref={ref}
                type={visible ? 'text' : 'password'}
                className={cn('form-control', className)}
            />
            <button
                type="button"
                className="app-password__toggle"
                onClick={() => setVisible((value) => !value)}
                aria-label={visible ? 'Hide password' : 'Show password'}
                tabIndex={-1}
            >
                {visible ? (
                    <EyeOff aria-hidden="true" />
                ) : (
                    <Eye aria-hidden="true" />
                )}
            </button>
        </div>
    );
}
