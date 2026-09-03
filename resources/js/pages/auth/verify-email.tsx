import { Form, Head } from '@inertiajs/react';
import Alert from '@/components/alert';
import Spinner from '@/components/spinner';
import TextLink from '@/components/text-link';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <Alert tone="success" className="mb-3">
                    <p>
                        A new verification link has been sent to the email
                        address you registered with.
                    </p>
                </Alert>
            )}

            <Form {...send.form()}>
                {({ processing }) => (
                    <button
                        type="submit"
                        className="btn btn-gradient btn-lg w-100"
                        disabled={processing}
                    >
                        {processing && <Spinner size="sm" />}
                        Resend verification email
                    </button>
                )}
            </Form>

            <p className="app-auth__footer">
                <TextLink href={logout()}>Log out</TextLink>
            </p>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email',
    description:
        'Click the link we just sent you to activate your account. Nothing arrived? Send it again.',
};
