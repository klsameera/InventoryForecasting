import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';

type Props = {
    id?: string;
    label?: string;
    hint?: string;
    error?: string;
    required?: boolean;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
};

/**
 * Label + control + hint + server-side error, in the one arrangement every form
 * in the application uses.
 */
export default function FormField({
    id,
    label,
    hint,
    error,
    required,
    action,
    className,
    children,
}: Props) {
    return (
        <div className={cn('app-field', error && 'is-invalid', className)}>
            {(label || action) && (
                <div className="app-field__label-row">
                    {label && (
                        <label className="form-label" htmlFor={id}>
                            {label}
                            {required && (
                                <span
                                    className="app-field__required"
                                    aria-hidden="true"
                                >
                                    *
                                </span>
                            )}
                        </label>
                    )}
                    {action}
                </div>
            )}

            {children}

            {hint && !error && <p className="app-field__hint">{hint}</p>}

            <InputError message={error} />
        </div>
    );
}
