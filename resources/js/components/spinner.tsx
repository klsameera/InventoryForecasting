import { cn } from '@/lib/utils';

type Props = {
    size?: 'sm' | 'md' | 'lg';
    className?: string;
    label?: string;
};

export default function Spinner({ size = 'md', className, label }: Props) {
    return (
        <span
            className={cn(
                'app-spinner',
                size === 'sm' && 'app-spinner--sm',
                size === 'lg' && 'app-spinner--lg',
                className,
            )}
            role="status"
            aria-label={label ?? 'Loading'}
        />
    );
}
