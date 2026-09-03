import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Props = {
    items: BreadcrumbItem[];
};

export default function Breadcrumbs({ items }: Props) {
    if (items.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb">
            <ol className="app-breadcrumb">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <li
                            key={`${toUrl(item.href)}-${index}`}
                            aria-current={isLast ? 'page' : undefined}
                        >
                            {isLast ? (
                                item.title
                            ) : (
                                <Link href={item.href}>{item.title}</Link>
                            )}

                            {!isLast && (
                                <ChevronRight
                                    className="app-breadcrumb__sep"
                                    aria-hidden="true"
                                    size={14}
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
