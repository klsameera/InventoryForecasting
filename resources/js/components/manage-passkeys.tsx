import { router } from '@inertiajs/react';
import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import ConfirmDialog from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import PasskeyRegistration from '@/components/passkey-register';
import SectionCard from '@/components/section-card';
import StatusBadge from '@/components/status-badge';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];
    const [pending, setPending] = useState<Passkey | null>(null);
    const [processing, setProcessing] = useState(false);

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    const handleDelete = (): void => {
        if (!pending) {
            return;
        }

        setProcessing(true);

        router.delete(destroy.url(pending.id), {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setPending(null);
            },
        });
    };

    return (
        <SectionCard
            title="Passkeys"
            subtitle="Sign in without a password using your device."
            actions={<PasskeyRegistration onSuccess={() => router.reload()} />}
            flush
        >
            {passkeys.length === 0 ? (
                <EmptyState
                    compact
                    icon={KeyRound}
                    title="No passkeys yet"
                    description="Add a passkey to sign in with your fingerprint, face or device PIN."
                />
            ) : (
                <ul className="list-unstyled mb-0">
                    {passkeys.map((passkey) => (
                        <li
                            key={passkey.id}
                            className="d-flex align-items-center gap-3 p-3 px-4 app-border-top"
                        >
                            <span className="app-stat__icon">
                                <KeyRound aria-hidden="true" />
                            </span>

                            <div className="flex-grow-1 app-min-w-0">
                                <div className="d-flex align-items-center gap-2 flex-wrap">
                                    <p className="app-table__primary mb-0">
                                        {passkey.name}
                                    </p>
                                    {passkey.authenticator && (
                                        <StatusBadge
                                            dot={false}
                                            outline
                                            label={passkey.authenticator}
                                        />
                                    )}
                                </div>
                                <p className="app-table__secondary mb-0">
                                    Added {passkey.created_at_diff}
                                    {passkey.last_used_at_diff &&
                                        ` · Last used ${passkey.last_used_at_diff}`}
                                </p>
                            </div>

                            <button
                                type="button"
                                className="btn btn-quiet-danger btn-icon btn-sm"
                                onClick={() => setPending(passkey)}
                                aria-label={`Remove ${passkey.name}`}
                            >
                                <Trash2 aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={pending !== null}
                onCancel={() => setPending(null)}
                onConfirm={handleDelete}
                processing={processing}
                title="Remove passkey"
                description={
                    pending
                        ? `"${pending.name}" will no longer be able to sign you in.`
                        : undefined
                }
                confirmLabel="Remove passkey"
            />
        </SectionCard>
    );
}
