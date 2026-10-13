import { Head } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import DifficultyBadge from '@/components/difficulty-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const scaleSteps = [
    50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950,
] as const;

const strands = [
    { name: 'Number', className: 'bg-strand-number' },
    { name: 'Algebra', className: 'bg-strand-algebra' },
    { name: 'Statistics', className: 'bg-strand-statistics' },
    { name: 'Geometry', className: 'bg-strand-geometry' },
];

function Swatches({ scale }: { scale: 'royal' | 'gold' }) {
    return (
        <div className="grid grid-cols-6 gap-2 sm:grid-cols-11">
            {scaleSteps.map((step) => (
                <div key={step} className="flex flex-col gap-1">
                    <div
                        className="h-12 rounded-md border"
                        style={{
                            backgroundColor: `var(--color-${scale}-${step})`,
                        }}
                    />
                    <span className="text-xs text-muted-foreground">
                        {step}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function Styleguide() {
    return (
        <>
            <Head title="Styleguide" />
            <main className="mx-auto flex max-w-5xl flex-col gap-10 px-4 py-10">
                <header className="flex flex-col gap-4">
                    <AppLogo className="text-4xl" />
                    <h1 className="font-display text-3xl font-bold tracking-wide uppercase">
                        Design system
                    </h1>
                    <p className="text-muted-foreground">
                        Tokens and primitives. Source: resources/css/app.css and
                        docs/design/design-system.md.
                    </p>
                </header>

                <section className="flex flex-col gap-4">
                    <h2 className="text-xl font-semibold">Brand colours</h2>
                    <Swatches scale="royal" />
                    <Swatches scale="gold" />
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-xl font-semibold">Buttons</h2>
                    <div className="flex flex-wrap gap-3">
                        <Button>Start quiz</Button>
                        <Button variant="accent">Subscribe</Button>
                        <Button variant="outline">View report</Button>
                        <Button variant="ghost">Cancel</Button>
                        <Button variant="danger">Mark absent</Button>
                        <Button disabled>Disabled</Button>
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-xl font-semibold">Status badges</h2>
                    <div className="flex flex-wrap gap-2">
                        <AttendanceStatusBadge status="present" />
                        <AttendanceStatusBadge status="late" minutesLate={7} />
                        <AttendanceStatusBadge status="absent" />
                        <AttendanceStatusBadge status="excused" />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <DifficultyBadge difficulty="easy" />
                        <DifficultyBadge difficulty="intermediate" />
                        <DifficultyBadge difficulty="hard" />
                        <DifficultyBadge difficulty="challenging" />
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {strands.map((strand) => (
                            <span
                                key={strand.name}
                                className="inline-flex items-center gap-2 text-sm"
                            >
                                <span
                                    className={`size-3 rounded-full ${strand.className}`}
                                />
                                {strand.name}
                            </span>
                        ))}
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Year 5 · Fractions</CardTitle>
                            <CardDescription>
                                10 questions · about 12 minutes
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex items-center justify-between">
                            <DifficultyBadge difficulty="intermediate" />
                            <Button size="sm">Start</Button>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Sign in</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder="parent@example.com"
                                />
                            </div>
                            <Button className="w-full">Continue</Button>
                        </CardContent>
                    </Card>
                </section>
            </main>
        </>
    );
}
