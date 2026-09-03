import { X } from 'lucide-react';
import { useEffect, useId } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    icon?: ComponentType<{ 'aria-hidden'?: boolean | 'true' | 'false' }>;
    iconTone?: 'brand' | 'danger' | 'warning';
    size?: 'sm' | 'md' | 'lg';
    footer?: ReactNode;
    children?: ReactNode;
};

/**
 * React-owned modal built on the project's Bootstrap styling. State lives in
 * React rather than Bootstrap's JS so the DOM has a single owner.
 */
export default function Modal({
    open,
    onClose,
    title,
    description,
    icon: Icon,
    iconTone = 'brand',
    size = 'md',
    footer,
    children,
}: Props) {
    const titleId = useId();
    const descriptionId = useId();

    useEffect(() => {
        if (!open) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="app-modal">
            <button
                type="button"
                className="app-modal__backdrop"
                onClick={onClose}
                aria-label="Close dialog"
                tabIndex={-1}
            />

            <div
                className={cn(
                    'app-modal__dialog',
                    size !== 'md' && `app-modal__dialog--${size}`,
                )}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description ? descriptionId : undefined}
            >
                <div className="app-modal__header">
                    {Icon && (
                        <span
                            className={cn(
                                'app-modal__icon',
                                iconTone !== 'brand' &&
                                    `app-modal__icon--${iconTone}`,
                            )}
                        >
                            <Icon aria-hidden="true" />
                        </span>
                    )}

                    <div className="app-min-w-0">
                        <h2 className="app-modal__title" id={titleId}>
                            {title}
                        </h2>
                        {description && (
                            <p
                                className="app-modal__description"
                                id={descriptionId}
                            >
                                {description}
                            </p>
                        )}
                    </div>

                    <button
                        type="button"
                        className="app-icon-btn app-modal__close"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X aria-hidden="true" />
                    </button>
                </div>

                {children && <div className="app-modal__body">{children}</div>}

                {footer && <div className="app-modal__footer">{footer}</div>}
            </div>
        </div>
    );
}
