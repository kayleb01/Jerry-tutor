import { cn } from '@/lib/utils';

type AppLogoImageProps = {
    className?: string;
    /** Always sit the logo on a light panel, e.g. in the dark sidebar. Uses the tightly cropped copy. */
    onDark?: boolean;
    /** Show only the crest (crown and shield), for tight spaces like the collapsed sidebar. */
    crest?: boolean;
};

/**
 * The full raster logo. Its baked-in light grey background is blended away in
 * light mode and sits on a light panel in dark mode, where the indigo
 * lettering would otherwise disappear.
 */
export default function AppLogoImage({
    className,
    onDark = false,
    crest = false,
}: AppLogoImageProps) {
    const image = crest
        ? { src: '/images/brand/logo-crest.png', width: 420, height: 558 }
        : onDark
          ? { src: '/images/brand/logo-tight.png', width: 1270, height: 560 }
          : { src: '/images/brand/logo.png', width: 740, height: 340 };

    return (
        <span
            className={cn(
                'inline-flex rounded-lg',
                onDark
                    ? 'bg-white px-3 py-2'
                    : 'dark:bg-[#f2f2f2] dark:px-3 dark:py-2',
                className,
            )}
        >
            <img
                src={image.src}
                alt="Jerry Great Academy"
                width={image.width}
                height={image.height}
                className={cn(
                    'h-auto w-full',
                    !onDark && 'mix-blend-multiply dark:mix-blend-normal',
                )}
            />
        </span>
    );
}
