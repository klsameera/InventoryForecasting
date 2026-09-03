import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Alert from '@/components/alert';
import Avatar from '@/components/avatar';
import DeleteUser from '@/components/delete-user';
import FormField from '@/components/form-field';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
};

export default function Profile({ mustVerifyEmail, status }: Props) {
    const { auth } = usePage().props;
    const verified = auth.user.email_verified_at !== null;

    return (
        <>
            <Head title="Profile settings" />

            <SectionCard
                title="Profile"
                subtitle="This is how your name and email appear across the app."
            >
                <div className="d-flex align-items-center gap-3 mb-4">
                    <Avatar
                        name={auth.user.name}
                        src={auth.user.avatar}
                        size="lg"
                    />
                    <div className="app-min-w-0">
                        <p className="fw-semibold mb-1">{auth.user.name}</p>
                        <StatusBadge
                            tone={verified ? 'success' : 'warning'}
                            label={verified ? 'Email verified' : 'Unverified'}
                        />
                    </div>
                </div>

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <FormField
                                id="name"
                                label="Full name"
                                error={errors.name}
                                required
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-control"
                                    defaultValue={auth.user.name}
                                    autoComplete="name"
                                    placeholder="Full name"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="email"
                                label="Email address"
                                error={errors.email}
                                required
                                hint="Changing this will require you to verify the new address."
                            >
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    className="form-control"
                                    defaultValue={auth.user.email}
                                    autoComplete="username"
                                    placeholder="Email address"
                                    required
                                />
                            </FormField>

                            {mustVerifyEmail && !verified && (
                                <Alert tone="warning" className="mt-3">
                                    <p>
                                        Your email address is unverified.{' '}
                                        <Link
                                            href={send()}
                                            as="button"
                                            className="app-text-link"
                                        >
                                            Resend the verification email
                                        </Link>
                                        .
                                    </p>
                                </Alert>
                            )}

                            {status === 'verification-link-sent' && (
                                <Alert tone="success" className="mt-3">
                                    <p>
                                        A new verification link has been sent to
                                        your email address.
                                    </p>
                                </Alert>
                            )}

                            <div className="app-form-actions mt-4">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Save changes
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </SectionCard>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: 'Profile settings', href: edit() }],
};
