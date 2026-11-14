import { useSplit } from '../../../plan/useSplit'

/** Хроніка й Пошук: задача праворуч — загальний розділений екран «Плану». */
export function useSplitTask(name: string) {
    return useSplit(name)
}
