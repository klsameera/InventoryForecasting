import { Monitor, Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const OPTIONS = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
] as const satisfies ReadonlyArray<{
    value: Appearance;
    label: string;
    icon: typeof Sun;
}>;

type Props = {
    /** `compact` renders a single cycling button for the topbar. */
    variant?: 'group' | 'compact';
};

export default function ThemeToggle({ variant = 'group' }: Props) {
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();

    if (variant === 'compact') {
        const nextMode: Appearance =
            resolvedAppearance === 'dark' ? 'light' : 'dark';
        const Icon = resolvedAppearance === 'dark' ? Sun : Moon;

        return (
            <button
                type="button"
                className="app-icon-btn"
                onClick={() => updateAppearance(nextMode)}
                aria-label={`Switch to ${nextMode} theme`}
                title={`Switch to ${nextMode} theme`}
            >
                <Icon aria-hidden="true" />
            </button>
        );
    }

    return (
        <div className="btn-group-pills" role="group" aria-label="Colour theme">
            {OPTIONS.map(({ value, label, icon: Icon }) => (
                <button
                    key={value}
                    type="button"
                    className={cn(
                        'btn btn-sm',
                        appearance === value && 'is-active',
                    )}
                    onClick={() => updateAppearance(value)}
                    aria-pressed={appearance === value}
                >
                    <Icon aria-hidden="true" />
                    {label}
                </button>
            ))}
        </div>
    );
}
