import BranchController from './BranchController'
import PackageController from './PackageController'

const Admin = {
    BranchController: Object.assign(BranchController, BranchController),
    PackageController: Object.assign(PackageController, PackageController),
}

export default Admin