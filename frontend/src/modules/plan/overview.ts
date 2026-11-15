import { call } from '../../app/api'
import type { TaskItem } from '../tasks/api'

/** «Огляд» v2 — одна відповідь на весь екран (/api/plan/overview, DirectorOverview). */
export interface Counts3 {
    open: number
    overdue: number
    stale: number
}

export interface DecisionAction {
    to: string
    label: string
    reject: boolean
    reasonRequired: boolean
}

export interface Decision {
    kind: 'task' | 'supply'
    id: number
    number: string
    title: string
    detail: string | null
    status: string
    statusLabel: string
    due: string | null
    overdue: boolean
    since: string
    person: { id?: number | null; name: string; phone?: string | null; tel?: string | null } | null
    amount: number | null
    actions: DecisionAction[]
}

export interface FleetCar {
    id: number
    number: string
    model: string | null
    today: number[]
    tomorrow: number[]
}

export interface Overview {
    generatedAt: string
    scope: { all: boolean; department: string | null; decideTasks: boolean; decideSupply: boolean }
    kpi: {
        overdueTasks: number
        openTasks: number
        mineTasks: number
        staleTasks: number
        decisions: number
        supplyOverdue: number
        fleet: { busy: number; free: number } | null
        shipmentsToday: number | null
    }
    decisions: { items: Decision[]; total: number; tasks: number; supply: number }
    control: { rows: (Counts3 & { id: number; name: string })[]; none: Counts3; total: Counts3; staleDays: number }
    supply: {
        counts: { open: number; new: number; overdue: number; decide: number; mine: number }
        approve: boolean
        manage: boolean
        reports: boolean
        month: {
            from: string
            to: string
            spent: number
            purchases: number
            suppliers: { id: number; name: string; purchases: number; total: number; share: number }[]
            departments: { id: number | null; name: string; requests: number; total: number }[]
        } | null
    }
    fleet: { enabled: false } | { enabled: true; cars: FleetCar[]; busyToday: number; freeToday: number; busyTomorrow: number }
    warehouse: {
        shipmentsToday: number
        receiptsToday: number
        movesWeek: number
        repair: number
        items: number
        lowCount: number
        low: { id: number; name: string; unit: string; quantity: number; min: number }[]
    } | null
    mine: TaskItem[]
}

export type ListFilter = 'open' | 'overdue' | 'stale' | 'decide' | 'mine'

export interface TaskList {
    items: TaskItem[]
    total: number
    limit: number
    filter: { f: ListFilter; dep: string; departmentName: string | null; staleDays: number; all: boolean }
}

export const overviewApi = {
    get: () => call<Overview>('/api/plan/overview'),
    list: (f: string, dep: string) => {
        const params = new URLSearchParams()
        if (f) params.set('f', f)
        if (dep) params.set('dep', dep)
        return call<TaskList>('/api/tasks/list?' + params.toString())
    },
}
