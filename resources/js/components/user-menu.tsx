import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Settings, ShieldCheck } from 'lucide-react';
import Avatar from '@/components/avatar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

export default function UserMenu() {
    const { auth } = usePage().props;
    const cleanup = useMobileNavigation();

    const handleLogout = (): void => {
        cleanup();
        router.post(logout().url);
    };

    return (
        <div className="dropdown">
            <button
                type="button"
                className="app-user-trigger"
                data-bs-toggle="dropdown"
                data-bs-display="static"
                aria-expanded="false"
            >
                <Avatar name={auth.user.name} src={auth.user.avatar} />
                <span className="d-none d-md-block text-start app-min-w-0">
                    <span className="app-user-summary__name d-block">
                        {auth.user.name}
                    </span>
                    <span className="app-user-summary__meta d-block">
                        {auth.user.email}
                    </span>
                </span>
                <ChevronDown size={15} aria-hidden="true" />
            </button>

            <div className="dropdown-menu dropdown-menu-end">
                <div className="app-dropdown-user">
                    <Avatar
                        name={auth.user.name}
                        src={auth.user.avatar}
                        size="sm"
                    />
                    <div className="app-min-w-0">
                        <p className="app-user-summary__name">
                            {auth.user.name}
                        </p>
                        <p className="app-user-summary__meta">
                            {auth.user.email}
                        </p>
                    </div>
                </div>

                <Link
                    className="dropdown-item"
                    href={editProfile()}
                    onClick={cleanup}
                >
                    <Settings aria-hidden="true" />
                    Profile settings
                </Link>

                <Link
                    className="dropdown-item"
                    href={editSecurity()}
                    onClick={cleanup}
                >
                    <ShieldCheck aria-hidden="true" />
                    Security
                </Link>

                <hr className="dropdown-divider" />

                <button
                    type="button"
                    className="dropdown-item dropdown-item--danger"
                    onClick={handleLogout}
                >
                    <LogOut aria-hidden="true" />
                    Log out
                </button>
            </div>
        </div>
    );
}
