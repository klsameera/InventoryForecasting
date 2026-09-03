import { Form } from '@inertiajs/react';
import { Check, Copy, ScanLine } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Alert from '@/components/alert';
import InputError from '@/components/input-error';
import Modal from '@/components/modal';
import OtpInput from '@/components/otp-input';
import Spinner from '@/components/spinner';
import { useAppearance } from '@/hooks/use-appearance';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { confirm } from '@/routes/two-factor';

type SetupStepProps = {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    buttonText: string;
    onNextStep: () => void;
    errors: string[];
};

function TwoFactorSetupStep({
    qrCodeSvg,
    manualSetupKey,
    buttonText,
    onNextStep,
    errors,
}: SetupStepProps) {
    const { resolvedAppearance } = useAppearance();
    const [copiedText, copy] = useClipboard();
    const CopyIcon = copiedText === manualSetupKey ? Check : Copy;

    if (errors.length > 0) {
        return <Alert tone="danger" messages={errors} />;
    }

    return (
        <div className="d-flex flex-column gap-3">
            <div className="app-surface-sunken app-rounded-lg p-3 mx-auto">
                <div className="app-qr">
                    {qrCodeSvg ? (
                        <div
                            className="app-qr__code"
                            dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                            style={{
                                filter:
                                    resolvedAppearance === 'dark'
                                        ? 'invert(1) brightness(1.6)'
                                        : undefined,
                            }}
                        />
                    ) : (
                        <Spinner size="lg" />
                    )}
                </div>
            </div>

            <button
                type="button"
                className="btn btn-gradient w-100"
                onClick={onNextStep}
            >
                {buttonText}
            </button>

            <div className="app-auth__separator my-1">
                or enter the code manually
            </div>

            <div className="input-group">
                <input
                    type="text"
                    className="form-control app-text-mono"
                    value={manualSetupKey ?? ''}
                    readOnly
                    aria-label="Manual setup key"
                    placeholder="Loading…"
                />
                <button
                    type="button"
                    className="btn btn-surface"
                    onClick={() => manualSetupKey && copy(manualSetupKey)}
                    disabled={!manualSetupKey}
                    aria-label="Copy setup key"
                >
                    <CopyIcon aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}

function TwoFactorVerificationStep({
    onClose,
    onBack,
}: {
    onClose: () => void;
    onBack: () => void;
}) {
    const [code, setCode] = useState('');

    return (
        <Form
            {...confirm.form()}
            onSuccess={onClose}
            resetOnError
            resetOnSuccess
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
            }) => (
                <div className="d-flex flex-column gap-3">
                    <OtpInput
                        name="code"
                        value={code}
                        onChange={setCode}
                        length={OTP_MAX_LENGTH}
                        disabled={processing}
                        autoFocus
                    />

                    <InputError
                        message={errors?.confirmTwoFactorAuthentication?.code}
                        className="justify-content-center"
                    />

                    <div className="d-flex gap-2">
                        <button
                            type="button"
                            className="btn btn-surface flex-fill"
                            onClick={onBack}
                            disabled={processing}
                        >
                            Back
                        </button>
                        <button
                            type="submit"
                            className="btn btn-gradient flex-fill"
                            disabled={
                                processing || code.length < OTP_MAX_LENGTH
                            }
                        >
                            {processing && <Spinner size="sm" />}
                            Confirm
                        </button>
                    </div>
                </div>
            )}
        </Form>
    );
}

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorSetupModal({
    isOpen,
    onClose,
    requiresConfirmation,
    twoFactorEnabled,
    qrCodeSvg,
    manualSetupKey,
    clearSetupData,
    fetchSetupData,
    errors,
}: Props) {
    const [showVerificationStep, setShowVerificationStep] = useState(false);

    const modalConfig = useMemo(() => {
        if (twoFactorEnabled) {
            return {
                title: 'Two-factor authentication enabled',
                description:
                    'Scan the QR code or enter the setup key in your authenticator app.',
                buttonText: 'Close',
            };
        }

        if (showVerificationStep) {
            return {
                title: 'Verify authentication code',
                description:
                    'Enter the 6-digit code from your authenticator app.',
                buttonText: 'Continue',
            };
        }

        return {
            title: 'Enable two-factor authentication',
            description:
                'Scan the QR code or enter the setup key in your authenticator app to finish.',
            buttonText: 'Continue',
        };
    }, [twoFactorEnabled, showVerificationStep]);

    const handleClose = useCallback((): void => {
        if (twoFactorEnabled) {
            clearSetupData();
        }

        setShowVerificationStep(false);
        onClose();
    }, [clearSetupData, onClose, twoFactorEnabled]);

    const handleNextStep = useCallback((): void => {
        if (requiresConfirmation) {
            setShowVerificationStep(true);

            return;
        }

        clearSetupData();
        handleClose();
    }, [requiresConfirmation, clearSetupData, handleClose]);

    const fetchSetupDataRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchSetupDataRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg) {
            fetchSetupDataRef.current();
        }
    }, [isOpen, qrCodeSvg]);

    return (
        <Modal
            open={isOpen}
            onClose={handleClose}
            title={modalConfig.title}
            description={modalConfig.description}
            icon={ScanLine}
        >
            {showVerificationStep ? (
                <TwoFactorVerificationStep
                    onClose={handleClose}
                    onBack={() => setShowVerificationStep(false)}
                />
            ) : (
                <TwoFactorSetupStep
                    qrCodeSvg={qrCodeSvg}
                    manualSetupKey={manualSetupKey}
                    buttonText={modalConfig.buttonText}
                    onNextStep={handleNextStep}
                    errors={errors}
                />
            )}
        </Modal>
    );
}
