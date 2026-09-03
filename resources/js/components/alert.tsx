import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Tone = 'success' | 'danger' | 'warning' | 'info' | 'primary' | 'neutral';

const ICONS = {
    success: CircleCheck,
    danger: CircleAlert,
    warning: TriangleAlert,
    info: Info,
    primary: Info,
    neutral: Info,
} as const;

type Props = {
    tone?: Tone;
    title?: string;
    children?: ReactNode;
    /** Convenience for rendering a list of validation-style messages. */
    messages?: string[];
    className?: string;
};

export default function Alert({
    tone = 'info',
    title,
    children,
    messages,
    className,
}: Props) {
    const Icon = ICONS[tone];

    if (!children && !title && !messages?.length) {
        return null;
    }

    return (
        <div
            className={cn('app-alert', `app-alert--${tone}`, className)}
            role={tone === 'danger' ? 'alert' : 'status'}
        >
            <Icon aria-hidden="true" />

            <div className="app-alert__body">
                {title && <p className="app-alert__title">{title}</p>}
                {children}
                {messages && messages.length > 0 && (
                    <ul>
                        {messages.map((message, index) => (
                            <li key={index}>{message}</li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
