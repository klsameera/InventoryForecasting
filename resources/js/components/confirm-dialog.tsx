import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import Modal from '@/components/modal';
import Spinner from '@/components/spinner';

type Props = {
    open: boolean;
    onCancel: () => void;
    onConfirm: () => void;
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    processing?: boolean;
    children?: ReactNode;
};

/**
 * Guard rail for irreversible actions. Every delete in the application goes
 * through this rather than `window.confirm`.
 */
export default function ConfirmDialog({
    open,
    onCancel,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    destructive = true,
    processing = false,
    children,
}: Props) {
    return (
        <Modal
            open={open}
            onClose={onCancel}
            title={title}
            description={description}
            icon={TriangleAlert}
            iconTone={destructive ? 'danger' : 'warning'}
            size="sm"
            footer={
                <>
                    <button
                        type="button"
                        className="btn btn-surface"
                        onClick={onCancel}
                        disabled={processing}
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        className={
                            destructive ? 'btn btn-danger' : 'btn btn-gradient'
                        }
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing && <Spinner size="sm" />}
                        {confirmLabel}
                    </button>
                </>
            }
        >
            {children}
        </Modal>
    );
}
