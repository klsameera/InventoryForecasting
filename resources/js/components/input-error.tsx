import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
    message?: string;
    className?: string;
};

export default function InputError({ message, className }: Props) {
    if (!message) {
        return null;
    }

    return (
        <p className={cn('app-field__error', className)} role="alert">
            <AlertCircle aria-hidden="true" />
            {message}
        </p>
    );
}
