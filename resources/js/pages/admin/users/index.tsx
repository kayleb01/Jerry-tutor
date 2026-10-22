import { Form, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { index as tutorApplications } from '@/routes/admin/tutor-applications';
import { create, edit, index } from '@/routes/admin/users';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: string;
    yearGroup: string | null;
    deactivated: boolean;
    trialEndsLabel: string | null;
    applicationStatus: 'pending' | 'rejected' | null;
};

type UsersIndexProps = {
    users: Paginated<Row>;
    filters: {
        role: string | null;
        search: string;
        status: 'active' | 'deactivated';
    };
    roles: Option[];
};

export default function UsersIndex({ users, filters, roles }: UsersIndexProps) {
    const { pendingTutorCount } = usePage().props;
    const roleLabel = (value: string) =>
        roles.find((role) => role.value === value)?.label ?? value;

    return (
        <AppLayout title="Users" subtitle="Accounts">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, email or username"
                            className="w-64"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="role">Role</Label>
                        <Select
                            id="role"
                            name="role"
                            defaultValue={filters.role ?? ''}
                            className="w-44"
                        >
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="status">Status</Label>
                        <Select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="w-40"
                        >
                            <option value="active">Active</option>
                            <option value="deactivated">Deactivated</option>
                        </Select>
                    </div>
                    <Button type="submit" variant="outline">
                        Filter
                    </Button>
                </Form>
                <div className="flex flex-wrap items-center gap-3">
                    <Link
                        href={tutorApplications()}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-sm border border-input px-4 text-sm font-semibold text-foreground hover:bg-surface-muted"
                    >
                        Pending tutors ({pendingTutorCount ?? 0})
                    </Link>
                    <Link
                        href={create()}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-sm bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary-hover"
                    >
                        Add user
                    </Link>
                </div>
            </div>
            <Card>
                <CardContent className="relative overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Login
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-6 text-center text-muted-foreground sm:py-8"
                                    >
                                        No users match these filters.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr
                                    key={user.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 font-semibold">
                                        {user.name}
                                        {user.deactivated && (
                                            <Badge
                                                tone="absent"
                                                className="ml-2"
                                            >
                                                Deactivated
                                            </Badge>
                                        )}
                                        {user.trialEndsLabel && (
                                            <Badge tone="gold" className="ml-2">
                                                Trial · ends{' '}
                                                {user.trialEndsLabel}
                                            </Badge>
                                        )}
                                        {user.applicationStatus && (
                                            <Badge
                                                tone={
                                                    user.applicationStatus ===
                                                    'rejected'
                                                        ? 'absent'
                                                        : 'late'
                                                }
                                                className="ml-2"
                                            >
                                                {user.applicationStatus ===
                                                'rejected'
                                                    ? 'Rejected'
                                                    : 'Pending'}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {user.username ?? user.email}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge tone="brand">
                                            {roleLabel(user.role)}
                                        </Badge>
                                        {user.yearGroup && (
                                            <span className="ml-2 text-muted-foreground">
                                                {user.yearGroup}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={edit(user.id)}
                                            className="font-medium text-link underline-offset-4 hover:underline"
                                        >
                                            Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
            <nav
                className="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <span className="text-muted-foreground">
                    {users.total} users · page {users.current_page} of{' '}
                    {users.last_page}
                </span>
                <div className="flex gap-3">
                    {users.prev_page_url && (
                        <Link
                            href={users.prev_page_url}
                            preserveState
                            className="font-medium text-link"
                        >
                            Previous
                        </Link>
                    )}
                    {users.next_page_url && (
                        <Link
                            href={users.next_page_url}
                            preserveState
                            className="font-medium text-link"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
