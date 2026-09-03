import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();

    const [showSetupModal, setShowSetupModal] = useState(false);
    const previouslyEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (previouslyEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        previouslyEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <SectionCard
            title="Two-factor authentication"
            subtitle="Require a rotating pin from your authenticator app at sign-in."
            actions={
                <StatusBadge
                    tone={twoFactorEnabled ? 'success' : 'secondary'}
                    label={twoFactorEnabled ? 'Enabled' : 'Disabled'}
                    pulse={twoFactorEnabled}
                />
            }
        >
            {twoFactorEnabled ? (
                <div className="d-flex flex-column gap-3">
                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />

                    <Form {...disable.form()}>
                        {({ processing }) => (
                            <button
                                type="submit"
                                className="btn btn-soft-danger align-self-start"
                                disabled={processing}
                            >
                                {processing && <Spinner size="sm" />}
                                Disable two-factor authentication
                            </button>
                        )}
                    </Form>
                </div>
            ) : (
                <div className="d-flex flex-column gap-3 align-items-start">
                    <p className="app-text-muted mb-0">
                        Once enabled you will be asked for a secure pin from a
                        TOTP app each time you sign in.
                    </p>

                    {hasSetupData ? (
                        <button
                            type="button"
                            className="btn btn-gradient"
                            onClick={() => setShowSetupModal(true)}
                        >
                            <ShieldCheck aria-hidden="true" />
                            Continue setup
                        </button>
                    ) : (
                        <Form
                            {...enable.form()}
                            onSuccess={() => setShowSetupModal(true)}
                        >
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <Spinner size="sm" />
                                    ) : (
                                        <ShieldCheck aria-hidden="true" />
                                    )}
                                    Enable two-factor authentication
                                </button>
                            )}
                        </Form>
                    )}
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </SectionCard>
    );
}
