import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    src?: string | null;
    size?: 'sm' | 'md' | 'lg';
    circle?: boolean;
    className?: string;
};

export default function Avatar({
    name,
    src,
    size = 'md',
    circle = false,
    className,
}: Props) {
    const getInitials = useInitials();

    return (
        <span
            className={cn(
                'app-avatar',
                size !== 'md' && `app-avatar--${size}`,
                circle && 'app-avatar--circle',
                className,
            )}
            aria-hidden="true"
        >
            {src ? <img src={src} alt="" /> : getInitials(name)}
        </span>
    );
}
