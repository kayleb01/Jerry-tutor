import { Form, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
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
    const roleLabel = (value: string) =>
        roles.find((role) => role.value === value)?.label ?? value;

    return (
        <AppLayout title="Users">
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
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                >
                    Add user
                </Link>
            </div>
            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-surface-muted text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Login
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
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
                                        className="px-4 py-8 text-center text-muted-foreground"
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
                                    <td className="px-4 py-3 font-medium">
                                        {user.name}
                                        {user.deactivated && (
                                            <Badge
                                                tone="absent"
                                                className="ml-2"
                                            >
                                                Deactivated
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
                                            className="font-medium text-primary underline-offset-4 hover:underline"
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
                            className="font-medium text-primary"
                        >
                            Previous
                        </Link>
                    )}
                    {users.next_page_url && (
                        <Link
                            href={users.next_page_url}
                            preserveState
                            className="font-medium text-primary"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
