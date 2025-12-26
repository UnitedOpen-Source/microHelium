import { type VariantProps, cva } from 'class-variance-authority'

export { default as Alert } from './Alert.vue'
export { default as AlertTitle } from './AlertTitle.vue'
export { default as AlertDescription } from './AlertDescription.vue'

export const alertVariants = cva(
    'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground',
                destructive: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
                success: 'border-success/50 bg-success/10 text-success dark:border-success [&>svg]:text-success',
                warning: 'border-warning/50 bg-warning/10 text-warning dark:border-warning [&>svg]:text-warning',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    }
)

export type AlertVariants = VariantProps<typeof alertVariants>
