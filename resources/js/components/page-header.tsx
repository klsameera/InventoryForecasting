import type { ReactNode } from 'react';

type Props = {
    eyebrow?: ReactNode;
    title: string;
    description?: string;
    actions?: ReactNode;
};

export default function PageHeader({
    eyebrow,
    title,
    description,
    actions,
}: Props) {
    return (
        <header className="app-page-header">
            <div className="app-page-header__text">
                {eyebrow && <span className="app-eyebrow">{eyebrow}</span>}
                <h1 className="app-title mt-1">{title}</h1>
                {description && (
                    <p className="app-subtitle mt-1">{description}</p>
                )}
            </div>

            {actions && (
                <div className="app-page-header__actions">{actions}</div>
            )}
        </header>
    );
}
