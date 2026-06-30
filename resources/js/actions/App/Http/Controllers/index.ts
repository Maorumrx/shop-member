import Settings from './Settings'
import Auth from './Auth'
import Admin from './Admin'

const Controllers = {
    Settings: Object.assign(Settings, Settings),
    Auth: Object.assign(Auth, Auth),
    Admin: Object.assign(Admin, Admin),
}

export default Controllers